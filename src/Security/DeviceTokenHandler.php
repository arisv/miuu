<?php

namespace App\Security;

use App\Service\DeviceTokenService;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/** Bearer tokens for /api/: device tokens only, never the legacy remote token. */
class DeviceTokenHandler implements AccessTokenHandlerInterface
{
    public function __construct(private DeviceTokenService $deviceTokens, private CurrentDeviceToken $current)
    {
    }

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        $token = $this->deviceTokens->authenticate($accessToken);
        if (!$token) {
            throw new BadCredentialsException('Invalid or revoked device token.');
        }
        $this->current->set($token);
        $user = $token->getUser();
        return new UserBadge($user->getUserIdentifier(), fn () => $user);
    }
}
