<?php

return [
    'name' => 'User Connectors',
    'version' => '1.0.0',

    'routing' => [
        'prefix' => 'user-connectors',
        'middleware' => ['web', 'auth'],
    ],

    'guard' => 'web',

    'navigation' => [
        'user-connectors' => [
            'title' => 'Meine Connectoren',
            'icon' => 'heroicon-o-link',
            'route' => 'user-connectors.connections.index',
            'order' => 36,
        ],
    ],

    'oauth2' => [
        'providers' => [
            'microsoft365' => [
                'authorize_url' => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
                'token_url' => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
                'client_id' => env('UC_MICROSOFT365_CLIENT_ID'),
                'client_secret' => env('UC_MICROSOFT365_CLIENT_SECRET'),
                'redirect_domain' => env('UC_MICROSOFT365_OAUTH_REDIRECT_DOMAIN'),
                'scopes' => [
                    'openid', 'profile', 'email', 'offline_access',
                    'Mail.ReadWrite', 'Mail.Send',
                    'Calendars.ReadWrite',
                    'Chat.ReadWrite', 'ChannelMessage.Send',
                    'User.Read',
                ],
                'extra_params' => [
                    'prompt' => 'consent',
                ],
            ],
            'ringcentral' => [
                'authorize_url' => 'https://platform.ringcentral.com/restapi/oauth/authorize',
                'token_url' => 'https://platform.ringcentral.com/restapi/oauth/token',
                'client_id' => env('UC_RINGCENTRAL_CLIENT_ID'),
                'client_secret' => env('UC_RINGCENTRAL_CLIENT_SECRET'),
                'redirect_domain' => env('UC_RINGCENTRAL_OAUTH_REDIRECT_DOMAIN'),
                'scopes' => ['ReadCallLog', 'ReadAccounts', 'ReadMessages', 'SMS', 'RingOut'],
            ],
            'sipgate' => [
                'authorize_url' => 'https://login.sipgate.com/auth/realms/third-party/protocol/openid-connect/auth',
                'token_url' => 'https://login.sipgate.com/auth/realms/third-party/protocol/openid-connect/token',
                'client_id' => env('UC_SIPGATE_CLIENT_ID'),
                'client_secret' => env('UC_SIPGATE_CLIENT_SECRET'),
                'redirect_domain' => env('UC_SIPGATE_OAUTH_REDIRECT_DOMAIN'),
                'scopes' => ['all'],
            ],
        ],
    ],

    'microsoft365' => [
        'graph_base_url' => 'https://graph.microsoft.com/v1.0',
        'timeout' => ['default' => 30, 'connect' => 10],
        'subscriptions' => [
            'enabled' => true,
            'max_lifetime_seconds' => 244800, // ~2.83 days (under 3-day Graph limit)
            'resources' => [
                ['resource' => 'me/mailFolders/inbox/messages', 'changeType' => 'created,updated'],
                ['resource' => 'me/events', 'changeType' => 'created,updated,deleted'],
            ],
        ],
    ],

    'ringcentral' => [
        'api_base_url' => env('UC_RINGCENTRAL_API_BASE_URL', 'https://platform.ringcentral.com/restapi/v1.0'),
        'timeout' => ['default' => 30, 'connect' => 10],
        'subscriptions' => [
            'enabled' => true,
            'max_lifetime_seconds' => 86400, // 24 hours
            'event_filters' => [
                '/restapi/v1.0/account/~/extension/~/telephony/sessions',
                '/restapi/v1.0/account/~/extension/~/message-store',
            ],
        ],
    ],

    'sipgate' => [
        'api_base_url' => env('UC_SIPGATE_API_BASE_URL', 'https://api.sipgate.com/v2'),
        'timeout' => ['default' => 30, 'connect' => 10],
        'webhook' => [
            'signature_enabled' => env('UC_SIPGATE_WEBHOOK_SIGNATURE_ENABLED', false),
            'secret' => env('UC_SIPGATE_WEBHOOK_SECRET'),
        ],
    ],
];
