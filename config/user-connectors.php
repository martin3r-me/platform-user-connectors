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
        'user-connectors-settings' => [
            'title' => 'Connector-Settings',
            'icon' => 'heroicon-o-cog-6-tooth',
            'route' => 'user-connectors.connectors.settings',
            'order' => 37,
            'roles' => ['owner', 'admin'],
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
