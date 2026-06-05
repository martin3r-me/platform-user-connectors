<?php

namespace Platform\UserConnectors\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Platform\Core\Traits\HasContextFileReferences;

class UserConnectorCallSession extends Model
{
    use HasContextFileReferences;
    protected $table = 'user_connector_call_sessions';

    protected $fillable = [
        'connection_id',
        'connector_key',
        'external_call_id',
        'direction',
        'status',
        'from_number',
        'to_number',
        'answering_number',
        'started_at',
        'answered_at',
        'ended_at',
        'duration_seconds',
        'hangup_cause',
        'meta',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'answered_at' => 'datetime',
        'ended_at' => 'datetime',
        'duration_seconds' => 'integer',
        'meta' => 'array',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(UserConnectorConnection::class, 'connection_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(UserConnectorInboundEvent::class, 'external_id', 'external_call_id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['ringing', 'active']);
    }

    public function durationForHumans(): ?string
    {
        if ($this->duration_seconds === null) {
            return null;
        }

        $minutes = intdiv($this->duration_seconds, 60);
        $seconds = $this->duration_seconds % 60;

        if ($minutes > 0) {
            return "{$minutes}m {$seconds}s";
        }

        return "{$seconds}s";
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', ['ringing', 'active']);
    }

    public function scopeRecent(Builder $query, int $limit = 30): Builder
    {
        return $query->orderByRaw("CASE WHEN status IN ('ringing', 'active') THEN 0 ELSE 1 END")
            ->orderByDesc('started_at')
            ->limit($limit);
    }

    public function scopeForConnection(Builder $query, array $connectionIds): Builder
    {
        return $query->whereIn('connection_id', $connectionIds);
    }
}
