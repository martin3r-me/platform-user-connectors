<?php

namespace Platform\UserConnectors\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserConnectorMessageSession extends Model
{
    protected $table = 'user_connector_message_sessions';

    protected $fillable = [
        'connection_id',
        'connector_key',
        'external_message_id',
        'message_type',
        'direction',
        'from_identifier',
        'from_user_id',
        'to_identifier',
        'body_preview',
        'chat_id',
        'importance',
        'sent_at',
        'message_count',
        'last_message_at',
        'meta',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'last_message_at' => 'datetime',
        'message_count' => 'integer',
        'meta' => 'array',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(UserConnectorConnection::class, 'connection_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(UserConnectorInboundEvent::class, 'external_id', 'external_message_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(UserConnectorChatMessage::class, 'message_session_id')
            ->orderBy('sent_at');
    }

    public function isTeamsChat(): bool
    {
        return $this->message_type === 'teams_chat';
    }

    public function isSMS(): bool
    {
        return $this->message_type === 'sms';
    }

    public function scopeRecent(Builder $query, int $limit = 30): Builder
    {
        return $query->orderByDesc('sent_at')
            ->limit($limit);
    }

    public function scopeByType(Builder $query, string $type): Builder
    {
        return $query->where('message_type', $type);
    }

    public function scopeForConnection(Builder $query, array $connectionIds): Builder
    {
        return $query->whereIn('connection_id', $connectionIds);
    }
}
