<?php

namespace App\Actions;

use App\Models\Contact;
use App\Services\Mam\MamClient;
use App\Services\Mam\MamCookie;
use App\Services\Mam\MamException;
use App\Services\SyncInterval;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Tell MAM about the IP this container is using (gluetun's VPN IP when it
 * runs with network_mode: "service:gluetun").
 *
 * The same thing myanonamouse/seedboxapi does: call dynamicSeedbox.php with
 * the mam_id cookie, keep the rotated cookie MAM sends back, and log the
 * outcome. Without a mam_id it only looks up the IP via jsonIp.php.
 */
class SyncSeedboxIp
{
    public function __construct(
        private readonly MamClient $client,
        private readonly MamCookie $cookie,
        private readonly SyncInterval $interval,
    ) {}

    /**
     * @param  string  $trigger  schedule | manual | cookie
     */
    public function run(string $trigger = 'schedule'): Contact
    {
        // One contact at a time: the scheduler, "Update now" and saving a
        // mam_id must not race over the rotated cookie.
        return Cache::lock('mam-sync', 60)->block(30, fn () => $this->contact($trigger));
    }

    /** Whether an automatic update is due, given INTERVAL and the last contact. */
    public function isDue(): bool
    {
        $last = $this->lastContactAt();

        // 10s of slack so a once-a-minute scheduler does not skip a beat.
        return $last === null || $last->diffInSeconds(now()) >= $this->interval->seconds() - 10;
    }

    public function nextContactAt(): ?Carbon
    {
        if (! config('seedbox.auto_sync')) {
            return null;
        }

        $last = $this->lastContactAt();

        // The scheduler runs at the top of each minute.
        return $last === null
            ? now()->addMinute()->startOfMinute()
            : $last->copy()->addSeconds($this->interval->seconds() - 10)->ceilMinute();
    }

    private function lastContactAt(): ?Carbon
    {
        return Contact::query()->latest('id')->first()?->created_at;
    }

    private function contact(string $trigger): Contact
    {
        $this->cookie->syncFromEnvironment();
        $mamId = $this->cookie->get();

        try {
            if ($mamId === null) {
                $host = $this->client->hostInfo();
                Log::info("seedboxapi: no mam_id set yet; MAM sees {$host->ip}. Add one in the web UI.");

                return $this->record($trigger, $host->ip, $host->asn, $host->asOrg, [
                    'reached' => true,
                    'success' => null,
                    'http_status' => $host->httpStatus,
                    'message' => 'No mam_id set',
                ]);
            }

            $result = $this->client->updateSeedboxIp($mamId);

            if ($result->rotatedCookie !== null && $result->rotatedCookie !== $mamId) {
                $this->cookie->set($result->rotatedCookie);
            }

            $line = "seedboxapi: MAM said \"{$result->message}\" (HTTP {$result->httpStatus}) for {$result->ip}";
            match (true) {
                ! $result->success => Log::warning($line),
                str_contains(strtolower((string) $result->message), 'no change') => Log::debug($line),
                default => Log::info($line),
            };

            return $this->record($trigger, $result->ip, $result->asn, $result->asOrg, [
                'reached' => true,
                'success' => $result->success,
                'http_status' => $result->httpStatus,
                'message' => $result->message,
            ]);
        } catch (MamException $e) {
            Log::warning('seedboxapi: '.$e->getMessage());

            return $this->record($trigger, null, null, null, [
                'reached' => false,
                'success' => false,
                'message' => mb_substr($e->getMessage(), 0, 250),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $outcome
     */
    private function record(string $trigger, ?string $ip, ?int $asn, ?string $asOrg, array $outcome): Contact
    {
        return Contact::query()->create([
            'trigger' => $trigger,
            'ip' => $ip,
            'asn' => $asn,
            'as_org' => $asOrg,
            ...$outcome,
        ]);
    }
}
