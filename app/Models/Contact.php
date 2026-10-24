<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/**
 * One request to MAM and its outcome.
 *
 * @property string $trigger schedule | manual | cookie
 * @property bool $reached false when MAM could not be reached at all
 * @property bool|null $success MAM's "Success" flag (null when no mam_id was set)
 */
class Contact extends Model
{
    use Prunable;

    public const UPDATED_AT = null;

    protected $fillable = [
        'trigger', 'reached', 'success', 'http_status', 'message', 'ip', 'asn', 'as_org',
    ];

    protected function casts(): array
    {
        return [
            'reached' => 'boolean',
            'success' => 'boolean',
            'http_status' => 'integer',
            'asn' => 'integer',
        ];
    }

    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(config('seedbox.history_days')));
    }

    /**
     * A single word for the UI: updated, unchanged, throttled, rejected,
     * unreachable or waiting (no mam_id yet).
     */
    public function state(): string
    {
        return match (true) {
            ! $this->reached => 'unreachable',
            $this->success === null => 'waiting',
            $this->success && str_contains(strtolower((string) $this->message), 'no change') => 'unchanged',
            $this->success => 'updated',
            $this->http_status === 429 => 'throttled',
            default => 'rejected',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toDashboard(): array
    {
        return [
            'id' => $this->id,
            'at' => $this->created_at?->toIso8601String(),
            'trigger' => $this->trigger,
            'state' => $this->state(),
            'http_status' => $this->http_status,
            'message' => $this->message,
            'ip' => $this->ip,
            'asn' => $this->asn,
            'as_org' => $this->as_org,
        ];
    }
}
