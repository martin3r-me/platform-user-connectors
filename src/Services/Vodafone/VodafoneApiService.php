<?php

namespace Platform\UserConnectors\Services\Vodafone;

use Platform\UserConnectors\Services\RingCentral\RingCentralApiService;

class VodafoneApiService extends RingCentralApiService
{
    public function __construct(VodafoneConnectorService $connectorService)
    {
        parent::__construct($connectorService);
    }
}
