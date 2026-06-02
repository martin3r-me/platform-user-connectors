<?php

namespace Platform\UserConnectors\Contracts;

enum ConnectorCapability: string
{
    case Messages = 'messages';
    case Calendar = 'calendar';
    case Calls = 'calls';
    case Presence = 'presence';
}
