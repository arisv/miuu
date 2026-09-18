<?php

namespace App\Service;

use App\Entity\DeviceToken;
use App\Entity\User;
use App\Repository\DeviceTokenRepository;
use Doctrine\ORM\EntityManagerInterface;

class DeviceTokenService
{
    /** How often lastUsedAt is written back; one flush per device per window. */
    private const TOUCH_INTERVAL = 300;

    public function __construct(private EntityManagerInterface $em)
    {
    }

    /** @return array{0: DeviceToken, 1: string} the entity and the plaintext token (shown once) */
    public function issue(User $user, string $name, string $platform): array
    {
        $plaintext = bin2hex(random_bytes(32));
        $token = new DeviceToken(
            $user,
            mb_substr(trim($name) !== '' ? trim($name) : 'Device', 0, 100),
            strtolower(trim($platform)),
            self::hash($plaintext),
            substr($plaintext, 0, 8)
        );
        $this->em->persist($token);
        $this->em->flush();
        return [$token, $plaintext];
    }

    /** Resolves a plaintext token to its active device, or null when unknown, revoked or the account is disabled. */
    public function authenticate(#[\SensitiveParameter] string $plaintext): ?DeviceToken
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $plaintext)) {
            return null;
        }
        $token = $this->repository()->findActiveByHash(self::hash($plaintext));
        if (!$token || !$token->getUser()->getActive()) {
            return null;
        }
        $last = $token->getLastUsedAt();
        if ($last === null || time() - $last->getTimestamp() > self::TOUCH_INTERVAL) {
            $token->touch();
            $this->em->flush();
        }
        return $token;
    }

    /** @return DeviceToken[] */
    public function listActive(User $user): array
    {
        return $this->repository()->findActiveForUser($user);
    }

    public function revoke(User $owner, int $id): void
    {
        $token = $this->repository()->findOneActiveForUser($owner, $id);
        if (!$token) {
            throw new \Exception("Device {$id} not found");
        }
        $this->revokeToken($token);
    }

    public function revokeToken(DeviceToken $token): void
    {
        $token->revoke();
        $this->em->flush();
    }

    /** Revokes every active device of the user except the given one (e.g. after a password change). */
    public function revokeOthers(User $user, ?DeviceToken $keep): int
    {
        $count = 0;
        foreach ($this->repository()->findActiveForUser($user) as $token) {
            if ($keep && $token->getId() === $keep->getId()) {
                continue;
            }
            $token->revoke();
            $count++;
        }
        $this->em->flush();
        return $count;
    }

    public static function hash(#[\SensitiveParameter] string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    private function repository(): DeviceTokenRepository
    {
        /** @var DeviceTokenRepository $repo */
        $repo = $this->em->getRepository(DeviceToken::class);
        return $repo;
    }
}
