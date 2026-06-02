<?php

namespace Platform\UserConnectors\Services\Vodafone;

use Platform\UserConnectors\Services\RingCentral\RingCentralMessageConnector;

class VodafoneMessageConnector extends RingCentralMessageConnector
{
    public function __construct(VodafoneApiService $api)
    {
        parent::__construct($api);
    }
}
