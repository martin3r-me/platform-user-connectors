<?php

namespace Platform\UserConnectors\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserConnectorChatMessage extends Model
{
    protected $table = 'user_connector_chat_messages';

    protected $fillable = [
        'message_session_id',
        'external_message_id',
        'from_identifier',
        'from_user_id',
        'body_preview',
        'body',
        'importance',
        'direction',
        'sent_at',
        'meta',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'meta' => 'array',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(UserConnectorMessageSession::class, 'message_session_id');
    }
}
