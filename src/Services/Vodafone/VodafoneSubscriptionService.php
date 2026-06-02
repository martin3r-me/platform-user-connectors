<?php

namespace Platform\UserConnectors\Services\Vodafone;

use Platform\UserConnectors\Services\RingCentral\RingCentralSubscriptionService;

class VodafoneSubscriptionService extends RingCentralSubscriptionService
{
    public function __construct(VodafoneConnectorService $connectorService)
    {
        parent::__construct($connectorService);
    }
}
