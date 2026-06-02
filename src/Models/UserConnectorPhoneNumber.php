<?php

namespace Platform\UserConnectors\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserConnectorPhoneNumber extends Model
{
    protected $table = 'user_connector_phone_numbers';

    protected $fillable = [
        'connection_id',
        'number',
        'label',
        'type',
        'capabilities',
        'is_default',
        'external_id',
        'meta',
    ];

    protected $casts = [
        'capabilities' => 'array',
        'is_default' => 'boolean',
        'meta' => 'array',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(UserConnectorConnection::class, 'connection_id');
    }

    public function scopeForConnection(Builder $query, int $connectionId): Builder
    {
        return $query->where('connection_id', $connectionId);
    }

    public function scopeWithCapability(Builder $query, string $capability): Builder
    {
        return $query->whereJsonContains('capabilities', $capability);
    }

    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }
}
