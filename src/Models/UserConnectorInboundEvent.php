<?php

namespace Platform\UserConnectors\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserConnectorInboundEvent extends Model
{
    protected $table = 'user_connector_inbound_events';

    protected $fillable = [
        'connection_id',
        'connector_key',
        'event_type',
        'direction',
        'external_id',
        'idempotency_key',
        'from_identifier',
        'to_identifier',
        'payload',
        'meta',
        'processing_status',
        'processing_error',
        'session_correlated_at',
        'event_timestamp',
    ];

    protected $casts = [
        'payload' => 'array',
        'meta' => 'array',
        'event_timestamp' => 'datetime',
        'session_correlated_at' => 'datetime',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(UserConnectorConnection::class, 'connection_id');
    }

    public function markProcessing(): void
    {
        $this->update(['processing_status' => 'processing']);
    }

    public function markProcessed(array $meta = []): void
    {
        $this->update([
            'processing_status' => 'processed',
            'processing_error' => null,
            'meta' => array_merge($this->meta ?? [], $meta),
        ]);
    }

    public function markFailed(string $error): void
    {
        $this->update([
            'processing_status' => 'failed',
            'processing_error' => $error,
        ]);
    }

    public function markSessionCorrelated(): void
    {
        $this->update([
            'session_correlated_at' => now(),
            'processing_error' => null,
        ]);
    }

    public function markCorrelationFailed(string $error): void
    {
        $this->update([
            'processing_error' => substr($error, 0, 1000),
            'session_correlated_at' => null,
        ]);
    }
}
