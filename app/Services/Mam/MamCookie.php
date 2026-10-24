<?php

namespace App\Services\Mam;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * The stored mam_id, encrypted with APP_KEY.
 */
class MamCookie
{
    private const KEY = 'mam_id';

    private const ENV_HASH = 'mam_id_env_hash';

    public function get(): ?string
    {
        $encrypted = Setting::get(self::KEY);

        if ($encrypted === null) {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (DecryptException) {
            // APP_KEY changed: the stored cookie is unreadable.
            return null;
        }
    }

    public function set(string $value): void
    {
        Setting::put(self::KEY, Crypt::encryptString(self::clean($value)));
    }

    public function clear(): void
    {
        Setting::forget(self::KEY);
    }

    /** Last four characters, enough to recognise which cookie is stored. */
    public function masked(): ?string
    {
        $value = $this->get();

        return $value === null ? null : '••••'.substr($value, -4);
    }

    /**
     * Apply the mam_id from the environment when it is new or was changed
     * since it was last applied. A cookie saved in the UI or rotated by MAM
     * therefore survives restarts with the same environment.
     */
    public function syncFromEnvironment(): void
    {
        $env = self::clean((string) config('seedbox.mam_id'));

        if ($env === '') {
            return;
        }

        $hash = hash('sha256', $env);

        if (Setting::get(self::ENV_HASH) !== $hash) {
            $this->set($env);
            Setting::put(self::ENV_HASH, $hash);
        }
    }

    /** Accept "mam_id=abc" or a quoted value as pasted from a browser. */
    public static function clean(string $value): string
    {
        $value = trim($value, " \t\n\r\0\x0B\"';");

        return preg_replace('/^mam_id=/i', '', $value);
    }
}
