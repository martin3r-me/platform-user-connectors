<?php

namespace Platform\UserConnectors\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Platform\Core\Models\User;

class UserConnectorConnectionShare extends Model
{
    protected $table = 'user_connector_connection_shares';

    protected $fillable = [
        'connection_id',
        'user_id',
        'team_id',
        'capability_scope',
    ];

    protected $casts = [
        'capability_scope' => 'array',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(UserConnectorConnection::class, 'connection_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
