<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * Reads the newest entries of the app's log file (the "single" or "daily"
 * channel) for the dashboard. Only the tail of the file is read.
 */
class LogReader
{
    private const TAIL_BYTES = 131072;

    private const LINE = '/^\[(?<at>\d{4}-\d{2}-\d{2}[ T][\d:.+\-]+)\] \w+\.(?<level>[A-Z]+): (?<message>.*)$/';

    /**
     * @return list<array{at: string, level: string, message: string}>
     */
    public function latest(int $limit = 100): array
    {
        $file = $this->currentFile();

        if ($file === null) {
            return [];
        }

        $entries = [];

        foreach ($this->tail($file) as $line) {
            if (preg_match(self::LINE, $line, $m)) {
                $entries[] = [
                    'at' => $this->iso($m['at']),
                    'level' => strtolower($m['level']),
                    'message' => $this->clean($m['message']),
                ];
            } elseif ($entries !== [] && trim($line) !== '') {
                // Continuation (stack trace): keep the first lines only.
                $last = &$entries[array_key_last($entries)];
                if (substr_count($last['message'], "\n") < 6) {
                    $last['message'] .= "\n".rtrim($line);
                }
                unset($last);
            }
        }

        return array_slice(array_reverse($entries), 0, $limit);
    }

    /** The most recently written log file (laravel.log or laravel-YYYY-MM-DD.log). */
    private function currentFile(): ?string
    {
        $path = (string) config('logging.channels.daily.path');
        $info = pathinfo($path);

        $files = glob(($info['dirname'] ?? '.').'/'.$info['filename'].'*.'.($info['extension'] ?? 'log')) ?: [];
        $files = array_filter($files, 'is_readable');

        if ($files === []) {
            return null;
        }

        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));

        return $files[0];
    }

    /**
     * @return list<string>
     */
    private function tail(string $file): array
    {
        $handle = @fopen($file, 'rb');

        if ($handle === false) {
            return [];
        }

        $size = filesize($file) ?: 0;
        $offset = max(0, $size - self::TAIL_BYTES);
        fseek($handle, $offset);
        $data = stream_get_contents($handle) ?: '';
        fclose($handle);

        $lines = explode("\n", $data);

        if ($offset > 0) {
            array_shift($lines); // probably a partial line
        }

        return $lines;
    }

    /** Drop Monolog's empty context/extra suffix. */
    private function clean(string $message): string
    {
        return rtrim(preg_replace('/\s*(\[\]|\{\})\s*(\[\]|\{\})?\s*$/', '', $message));
    }

    /** Log times are written in the app timezone; send ISO 8601 to the browser. */
    private function iso(string $at): string
    {
        try {
            return Carbon::parse($at, config('app.timezone'))->toIso8601String();
        } catch (Throwable) {
            return $at;
        }
    }
}
