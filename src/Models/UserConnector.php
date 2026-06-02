<?php

namespace Platform\UserConnectors\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserConnector extends Model
{
    protected $table = 'user_connectors';

    protected $fillable = [
        'key',
        'name',
        'is_enabled',
        'capabilities',
        'supported_auth_schemes',
        'meta',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'capabilities' => 'array',
        'supported_auth_schemes' => 'array',
        'meta' => 'array',
    ];

    public function connections(): HasMany
    {
        return $this->hasMany(UserConnectorConnection::class, 'connector_id');
    }

    public function oauthApps(): HasMany
    {
        return $this->hasMany(UserConnectorOAuthApp::class, 'connector_id');
    }
}
