<?php

namespace App\Services;

use App\Models\Setting;

/**
 * Seconds between automatic updates. INTERVAL from the environment is the
 * default; a value saved in the UI wins until INTERVAL itself is changed.
 */
class SyncInterval
{
    public const MIN = 60;

    public const MAX = 86400;

    private const KEY = 'interval';

    private const ENV_MARKER = 'interval_env';

    public function seconds(): int
    {
        $this->syncFromEnvironment();

        return self::clamp((int) (Setting::get(self::KEY) ?? $this->environment()));
    }

    public function environment(): int
    {
        return self::clamp((int) config('seedbox.interval'));
    }

    public function set(int $seconds): void
    {
        $this->syncFromEnvironment();
        Setting::put(self::KEY, (string) self::clamp($seconds));
    }

    /** Whether the UI value differs from INTERVAL. */
    public function isCustom(): bool
    {
        $this->syncFromEnvironment();

        return Setting::get(self::KEY) !== null;
    }

    public function reset(): void
    {
        Setting::forget(self::KEY);
    }

    /** A changed INTERVAL replaces the value saved in the UI. */
    private function syncFromEnvironment(): void
    {
        $env = (string) $this->environment();

        if (Setting::get(self::ENV_MARKER) !== $env) {
            Setting::forget(self::KEY);
            Setting::put(self::ENV_MARKER, $env);
        }
    }

    private static function clamp(int $seconds): int
    {
        return max(self::MIN, min(self::MAX, $seconds));
    }
}
