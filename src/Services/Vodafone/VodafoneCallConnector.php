<?php

namespace Platform\UserConnectors\Services\Vodafone;

use Platform\UserConnectors\Services\RingCentral\RingCentralCallConnector;

class VodafoneCallConnector extends RingCentralCallConnector
{
    public function __construct(VodafoneApiService $api)
    {
        parent::__construct($api);
    }
}
