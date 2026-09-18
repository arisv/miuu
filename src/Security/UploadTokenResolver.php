<?php

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\DeviceTokenService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Resolves the `private_key` of a token upload: a per-device token from the app, or the
 * legacy account-wide remote token that ShareX-style clients keep using.
 */
class UploadTokenResolver
{
    public function __construct(private DeviceTokenService $deviceTokens, private EntityManagerInterface $em)
    {
    }

    public function resolveUser(#[\SensitiveParameter] string $token): ?User
    {
        if ($token === '') {
            return null;
        }
        $device = $this->deviceTokens->authenticate($token);
        if ($device) {
            return $device->getUser();
        }
        /** @var UserRepository $users */
        $users = $this->em->getRepository(User::class);
        return $users->findActiveUserByToken($token);
    }
}
