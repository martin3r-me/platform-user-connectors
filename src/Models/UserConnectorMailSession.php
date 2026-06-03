<?php

namespace Platform\UserConnectors\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserConnectorMailSession extends Model
{
    protected $table = 'user_connector_mail_sessions';

    protected $fillable = [
        'connection_id',
        'connector_key',
        'external_mail_id',
        'conversation_id',
        'direction',
        'status',
        'from_address',
        'from_name',
        'to_addresses',
        'cc_addresses',
        'subject',
        'body_preview',
        'is_read',
        'has_attachments',
        'is_draft',
        'shared_mailbox',
        'received_at',
        'sent_at',
        'meta',
    ];

    protected $casts = [
        'received_at' => 'datetime',
        'sent_at' => 'datetime',
        'is_read' => 'boolean',
        'has_attachments' => 'boolean',
        'is_draft' => 'boolean',
        'meta' => 'array',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(UserConnectorConnection::class, 'connection_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(UserConnectorInboundEvent::class, 'external_id', 'external_mail_id');
    }

    public function isUnread(): bool
    {
        return !$this->is_read;
    }

    public function timeForHumans(): ?string
    {
        $ts = $this->received_at ?? $this->sent_at;

        return $ts?->diffForHumans();
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->where('is_read', false);
    }

    public function scopeRecent(Builder $query, int $limit = 30): Builder
    {
        return $query->orderByRaw("CASE WHEN is_read = false THEN 0 ELSE 1 END")
            ->orderByDesc('received_at')
            ->limit($limit);
    }

    public function scopeForConnection(Builder $query, array $connectionIds): Builder
    {
        return $query->whereIn('connection_id', $connectionIds);
    }
}
