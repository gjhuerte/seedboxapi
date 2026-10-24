<?php

namespace Tests\Feature;

use App\Actions\SyncSeedboxIp;
use App\Models\Contact;
use App\Services\Mam\MamCookie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncSeedboxIpTest extends TestCase
{
    use RefreshDatabase;

    private const UPDATE = 't.myanonamouse.net/json/dynamicSeedbox.php';

    private const IP = 't.myanonamouse.net/json/jsonIp.php';

    protected function setUp(): void
    {
        parent::setUp();

        config(['seedbox.mam_id' => null, 'seedbox.interval' => 600]);
        Http::preventStrayRequests();
    }

    /**
     * @return array<string, mixed>
     */
    private function mam(string $msg, bool $success = true, string $ip = '185.65.134.82'): array
    {
        return ['Success' => $success, 'msg' => $msg, 'ip' => $ip, 'ASN' => 9009, 'AS' => 'M247 Europe SRL'];
    }

    private function cookie(): MamCookie
    {
        return app(MamCookie::class);
    }

    public function test_it_registers_the_ip_and_keeps_the_rotated_cookie(): void
    {
        $this->cookie()->set('old-cookie');
        Http::fake([self::UPDATE => Http::response($this->mam('Completed'), 200, [
            'Set-Cookie' => 'mam_id=new-cookie; expires=Sat, 25-Oct-2027 10:00:00 GMT; path=/; domain=.myanonamouse.net; secure; HttpOnly',
        ])]);

        $contact = app(SyncSeedboxIp::class)->run();

        $this->assertSame('updated', $contact->state());
        $this->assertSame('185.65.134.82', $contact->ip);
        $this->assertSame(9009, $contact->asn);
        $this->assertSame('M247 Europe SRL', $contact->as_org);
        $this->assertSame('new-cookie', $this->cookie()->get());
        Http::assertSent(fn (Request $request) => $request->hasHeader('Cookie', 'mam_id=old-cookie'));
    }

    public function test_no_change_is_a_success(): void
    {
        $this->cookie()->set('cookie');
        Http::fake([self::UPDATE => Http::response($this->mam('No change'))]);

        $this->assertSame('unchanged', app(SyncSeedboxIp::class)->run()->state());
        $this->assertSame('cookie', $this->cookie()->get());
    }

    public function test_a_too_recent_change_is_throttled(): void
    {
        $this->cookie()->set('cookie');
        Http::fake([self::UPDATE => Http::response($this->mam('Last Change too recent', false), 429)]);

        $contact = app(SyncSeedboxIp::class)->run();

        $this->assertSame('throttled', $contact->state());
        $this->assertSame(429, $contact->http_status);
    }

    public function test_a_rejected_session_is_recorded(): void
    {
        $this->cookie()->set('cookie');
        Http::fake([self::UPDATE => Http::response($this->mam('Invalid session - IP mismatch', false), 403)]);

        $contact = app(SyncSeedboxIp::class)->run();

        $this->assertSame('rejected', $contact->state());
        $this->assertSame('Invalid session - IP mismatch', $contact->message);
    }

    public function test_an_unreachable_mam_is_recorded_instead_of_thrown(): void
    {
        $this->cookie()->set('cookie');
        Http::fake([self::UPDATE => Http::failedConnection('Connection timed out')]);

        $contact = app(SyncSeedboxIp::class)->run();

        $this->assertSame('unreachable', $contact->state());
        $this->assertFalse($contact->reached);
        $this->assertStringContainsString('Could not reach MAM', $contact->message);
    }

    public function test_a_non_json_answer_is_recorded(): void
    {
        $this->cookie()->set('cookie');
        Http::fake([self::UPDATE => Http::response('<html>Bad gateway</html>', 502)]);

        $this->assertSame('unreachable', app(SyncSeedboxIp::class)->run()->state());
    }

    public function test_without_a_mam_id_it_only_looks_up_the_ip(): void
    {
        Http::fake([self::IP => Http::response(['ip' => '185.65.134.82', 'ASN' => 9009, 'AS' => 'M247 Europe SRL'])]);

        $contact = app(SyncSeedboxIp::class)->run();

        $this->assertSame('waiting', $contact->state());
        $this->assertSame('185.65.134.82', $contact->ip);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'dynamicSeedbox'));
    }

    public function test_the_environment_mam_id_is_applied_only_when_it_changes(): void
    {
        config(['seedbox.mam_id' => 'env-one']);
        $this->cookie()->syncFromEnvironment();
        $this->assertSame('env-one', $this->cookie()->get());

        // Saved in the UI (or rotated by MAM): survives a restart with the same env.
        $this->cookie()->set('from-ui');
        $this->cookie()->syncFromEnvironment();
        $this->assertSame('from-ui', $this->cookie()->get());

        config(['seedbox.mam_id' => 'env-two']);
        $this->cookie()->syncFromEnvironment();
        $this->assertSame('env-two', $this->cookie()->get());
    }

    public function test_pasted_values_are_cleaned(): void
    {
        $this->assertSame('abc123', MamCookie::clean(' mam_id=abc123; '));
        $this->assertSame('abc123', MamCookie::clean('"abc123"'));
    }

    public function test_the_cookie_is_stored_encrypted(): void
    {
        $this->cookie()->set('secret-cookie');

        $this->assertDatabaseMissing('settings', ['value' => 'secret-cookie']);
        $this->assertSame('••••okie', $this->cookie()->masked());
    }

    public function test_the_scheduled_command_waits_for_the_interval(): void
    {
        $this->cookie()->set('cookie');
        Http::fake([self::UPDATE => Http::response($this->mam('No change'))]);

        $this->artisan('mam:sync --if-due')->assertSuccessful();
        $this->artisan('mam:sync --if-due')->assertSuccessful();
        $this->assertSame(1, Contact::query()->count());

        $this->travel(10)->minutes();
        $this->artisan('mam:sync --if-due')->assertSuccessful();
        $this->assertSame(2, Contact::query()->count());
    }
}
