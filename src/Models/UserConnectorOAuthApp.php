<?php

namespace Platform\UserConnectors\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Platform\Core\Casts\EncryptedJson;
use Platform\Core\Traits\Encryptable;

class UserConnectorOAuthApp extends Model
{
    use Encryptable;

    protected $table = 'user_connector_oauth_apps';

    protected $fillable = [
        'connector_id',
        'name',
        'settings',
        'is_enabled',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'settings' => EncryptedJson::class,
    ];

    protected array $encryptable = [
        'settings' => 'json',
    ];

    public function connector(): BelongsTo
    {
        return $this->belongsTo(UserConnector::class, 'connector_id');
    }

    public function connections(): HasMany
    {
        return $this->hasMany(UserConnectorConnection::class, 'oauth_app_id');
    }

    /**
     * Merge own settings (client_id, client_secret) with config defaults (scopes, URLs).
     */
    public function getOAuthConfig(): array
    {
        $connectorKey = $this->connector->key ?? $this->connector()->value('key');
        $defaults = (array) config("user-connectors.oauth_defaults.{$connectorKey}", []);
        $appSettings = $this->settings ?? [];

        // App settings override defaults — allows per-app URL overrides
        // (e.g. sandbox vs production endpoints for RingCentral)
        return array_merge($defaults, array_filter($appSettings, fn ($v) => $v !== null && $v !== ''));
    }
}
