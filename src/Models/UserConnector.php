<?php

namespace Platform\UserConnectors\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Platform\Core\Casts\EncryptedJson;
use Platform\Core\Traits\Encryptable;

class UserConnector extends Model
{
    use Encryptable;

    protected $table = 'user_connectors';

    protected $fillable = [
        'key',
        'name',
        'is_enabled',
        'capabilities',
        'supported_auth_schemes',
        'meta',
        'settings',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'capabilities' => 'array',
        'supported_auth_schemes' => 'array',
        'meta' => 'array',
        'settings' => EncryptedJson::class,
    ];

    protected array $encryptable = [
        'settings' => 'json',
    ];

    public function connections(): HasMany
    {
        return $this->hasMany(UserConnectorConnection::class, 'connector_id');
    }

    /**
     * Get the OAuth configuration from settings, if configured.
     */
    public function getOAuthConfig(): ?array
    {
        $settings = $this->settings;

        if (!$settings || empty($settings['oauth'])) {
            return null;
        }

        return $settings['oauth'];
    }
}
