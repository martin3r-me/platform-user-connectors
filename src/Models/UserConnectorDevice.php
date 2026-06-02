<?php

namespace Platform\UserConnectors\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserConnectorDevice extends Model
{
    protected $table = 'user_connector_devices';

    protected $fillable = [
        'connection_id',
        'name',
        'type',
        'external_id',
        'is_online',
        'meta',
    ];

    protected $casts = [
        'is_online' => 'boolean',
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

    public function scopeOnline(Builder $query): Builder
    {
        return $query->where('is_online', true);
    }
}
