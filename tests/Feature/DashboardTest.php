<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Services\Mam\MamCookie;
use App\Services\SyncInterval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['seedbox.mam_id' => null]);
        Http::preventStrayRequests();
        $this->withoutVite();
    }

    public function test_it_shows_the_status_without_revealing_the_cookie(): void
    {
        app(MamCookie::class)->set('very-secret-1234');
        Contact::query()->create([
            'trigger' => 'schedule', 'reached' => true, 'success' => true, 'http_status' => 200,
            'message' => 'Completed', 'ip' => '185.65.134.82', 'asn' => 9009, 'as_org' => 'M247',
        ]);

        $response = $this->get('/');

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard', false)
            ->where('mamId.configured', true)
            ->where('mamId.masked', '••••1234')
            ->where('latest.state', 'updated')
            ->where('latest.ip', '185.65.134.82')
            ->has('contacts', 1)
            ->has('logs')
            ->where('schedule.interval', 600)
        );
        $response->assertDontSee('very-secret-1234');
    }

    public function test_saving_a_mam_id_stores_it_and_updates_mam(): void
    {
        Http::fake(['*dynamicSeedbox.php' => Http::response([
            'Success' => true, 'msg' => 'Completed', 'ip' => '185.65.134.82', 'ASN' => 9009, 'AS' => 'M247',
        ])]);

        $this->from('/')->put('/mam-id', ['mam_id' => 'mam_id=fresh-cookie'])->assertRedirect('/');

        $this->assertSame('fresh-cookie', app(MamCookie::class)->get());
        $this->assertSame('cookie', Contact::query()->latest('id')->value('trigger'));
    }

    public function test_a_mam_id_is_required(): void
    {
        $this->from('/')->put('/mam-id', ['mam_id' => ''])->assertSessionHasErrors('mam_id');
        $this->from('/')->put('/mam-id', ['mam_id' => 'has spaces'])->assertSessionHasErrors('mam_id');
    }

    public function test_the_mam_id_can_be_removed(): void
    {
        app(MamCookie::class)->set('cookie');

        $this->from('/')->delete('/mam-id')->assertRedirect('/');

        $this->assertNull(app(MamCookie::class)->get());
    }

    public function test_update_now_contacts_mam(): void
    {
        app(MamCookie::class)->set('cookie');
        Http::fake(['*dynamicSeedbox.php' => Http::response([
            'Success' => true, 'msg' => 'No change', 'ip' => '185.65.134.82', 'ASN' => 9009, 'AS' => 'M247',
        ])]);

        $this->from('/')->post('/sync')->assertRedirect('/');

        $this->assertSame('manual', Contact::query()->value('trigger'));
    }

    public function test_basic_auth_protects_the_ui_when_configured(): void
    {
        config(['seedbox.ui_username' => 'gab', 'seedbox.ui_password' => 'hunter2']);

        $this->get('/')->assertUnauthorized()->assertHeader('WWW-Authenticate');
        $this->get('/', ['Authorization' => 'Basic '.base64_encode('gab:wrong')])->assertUnauthorized();
        $this->get('/', ['Authorization' => 'Basic '.base64_encode('gab:hunter2')])->assertOk();
        $this->get('/up')->assertOk();
    }

    public function test_logs_are_listed_newest_first(): void
    {
        $path = storage_path('framework/testing/seedboxapi-test.log');
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, implode("\n", [
            '[2026-10-25 10:00:00] testing.INFO: first [] []',
            '[2026-10-25 10:10:00] testing.WARNING: second [] []',
            '',
        ]));
        config(['logging.channels.daily.path' => $path]);

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('logs.0.message', 'second')
            ->where('logs.0.level', 'warning')
            ->where('logs.1.message', 'first')
        );

        @unlink($path);
    }

    public function test_the_interval_can_be_changed_in_the_ui(): void
    {
        config(['seedbox.interval' => 600]);

        $this->from('/')->put('/interval', ['minutes' => 5])->assertRedirect('/');
        $this->assertSame(300, app(SyncInterval::class)->seconds());
        $this->assertTrue(app(SyncInterval::class)->isCustom());

        $this->from('/')->put('/interval', ['minutes' => 0])->assertSessionHasErrors('minutes');
        $this->from('/')->put('/interval', ['minutes' => 'often'])->assertSessionHasErrors('minutes');

        $this->from('/')->delete('/interval')->assertRedirect('/');
        $this->assertSame(600, app(SyncInterval::class)->seconds());
    }

    public function test_a_changed_interval_environment_variable_replaces_the_ui_value(): void
    {
        config(['seedbox.interval' => 600]);
        app(SyncInterval::class)->set(300);
        $this->assertSame(300, app(SyncInterval::class)->seconds());

        config(['seedbox.interval' => 900]);
        $this->assertSame(900, app(SyncInterval::class)->seconds());
        $this->assertFalse(app(SyncInterval::class)->isCustom());
    }

    public function test_the_interval_is_clamped(): void
    {
        config(['seedbox.interval' => 5]);

        $this->assertSame(60, app(SyncInterval::class)->seconds());
    }
}
