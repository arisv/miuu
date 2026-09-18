<?php

namespace App\Security;

use App\Entity\DeviceToken;

/** Request-scoped holder for the device token that authenticated the current API request. */
class CurrentDeviceToken
{
    private ?DeviceToken $token = null;

    public function set(?DeviceToken $token): void
    {
        $this->token = $token;
    }

    public function get(): ?DeviceToken
    {
        return $this->token;
    }
}
