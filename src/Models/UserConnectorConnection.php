<?php

namespace Platform\UserConnectors\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\Core\Casts\EncryptedJson;
use Platform\Core\Models\User;
use Platform\Core\Traits\Encryptable;

class UserConnectorConnection extends Model
{
    use SoftDeletes;
    use Encryptable;

    protected $table = 'user_connector_connections';

    protected $fillable = [
        'connector_id',
        'oauth_app_id',
        'owner_user_id',
        'name',
        'is_default',
        'auth_scheme',
        'status',
        'capabilities',
        'credentials',
        'credentials_hash',
        'last_tested_at',
        'last_error',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'capabilities' => 'array',
        'credentials' => EncryptedJson::class,
        'last_tested_at' => 'datetime',
    ];

    protected array $encryptable = [
        'credentials' => 'json',
    ];

    public function connector(): BelongsTo
    {
        return $this->belongsTo(UserConnector::class, 'connector_id');
    }

    public function oauthApp(): BelongsTo
    {
        return $this->belongsTo(UserConnectorOAuthApp::class, 'oauth_app_id');
    }

    public function ownerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function shares(): HasMany
    {
        return $this->hasMany(UserConnectorConnectionShare::class, 'connection_id');
    }

    public function phoneNumbers(): HasMany
    {
        return $this->hasMany(UserConnectorPhoneNumber::class, 'connection_id');
    }

    public function devices(): HasMany
    {
        return $this->hasMany(UserConnectorDevice::class, 'connection_id');
    }

    /**
     * Make this the default connection for its connector + owner.
     */
    public function makeDefault(): void
    {
        // Unset other defaults
        static::query()
            ->where('connector_id', $this->connector_id)
            ->where('owner_user_id', $this->owner_user_id)
            ->where('id', '!=', $this->id)
            ->update(['is_default' => false]);

        $this->is_default = true;
        $this->save();
    }

    /**
     * Generate a unique name for a new connection.
     */
    public static function generateName(int $connectorId, int $ownerUserId, string $baseName): string
    {
        $existing = static::query()
            ->where('connector_id', $connectorId)
            ->where('owner_user_id', $ownerUserId)
            ->count();

        return $existing === 0 ? $baseName : $baseName . ' (' . ($existing + 1) . ')';
    }
}
