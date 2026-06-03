<?php

namespace Platform\UserConnectors\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserConnectorMeetingSession extends Model
{
    protected $table = 'user_connector_meeting_sessions';

    protected $fillable = [
        'connection_id',
        'connector_key',
        'external_event_id',
        'direction',
        'status',
        'organizer_address',
        'organizer_name',
        'attendee_addresses',
        'subject',
        'body_preview',
        'location',
        'is_online_meeting',
        'online_meeting_url',
        'start_at',
        'end_at',
        'duration_minutes',
        'meta',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'duration_minutes' => 'integer',
        'is_online_meeting' => 'boolean',
        'meta' => 'array',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(UserConnectorConnection::class, 'connection_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(UserConnectorInboundEvent::class, 'external_id', 'external_event_id');
    }

    public function isUpcoming(): bool
    {
        return $this->status === 'upcoming';
    }

    public function isInProgress(): bool
    {
        return $this->status === 'in_progress';
    }

    public function updateStatusFromTime(): void
    {
        if (in_array($this->status, ['cancelled', 'deleted'])) {
            return;
        }

        $now = now();

        if ($this->start_at && $this->end_at) {
            if ($now->lt($this->start_at)) {
                $newStatus = 'upcoming';
            } elseif ($now->between($this->start_at, $this->end_at)) {
                $newStatus = 'in_progress';
            } else {
                $newStatus = 'completed';
            }

            if ($this->status !== $newStatus) {
                $this->update(['status' => $newStatus]);
            }
        }
    }

    public function durationForHumans(): ?string
    {
        if ($this->duration_minutes === null) {
            return null;
        }

        $hours = intdiv($this->duration_minutes, 60);
        $minutes = $this->duration_minutes % 60;

        if ($hours > 0 && $minutes > 0) {
            return "{$hours}h {$minutes}m";
        }

        if ($hours > 0) {
            return "{$hours}h";
        }

        return "{$minutes}m";
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', ['upcoming', 'in_progress']);
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('status', 'upcoming');
    }

    public function scopeRecent(Builder $query, int $limit = 30): Builder
    {
        return $query->orderByRaw("CASE WHEN status IN ('upcoming', 'in_progress') THEN 0 ELSE 1 END")
            ->orderByDesc('start_at')
            ->limit($limit);
    }
}
