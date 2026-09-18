<?php

namespace App\Controller\Api;

use App\Entity\DeviceToken;
use App\Entity\User;
use App\Exception\ApiException;
use App\Repository\UserRepository;
use App\Security\CurrentDeviceToken;
use App\Security\UserChecker;
use App\Service\DeviceTokenService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccountStatusException;

#[Route('/api/v1/auth')]
class AuthController extends AbstractController
{
    /** Same wording for unknown users and wrong passwords, so logins cannot be enumerated. */
    private const INVALID_CREDENTIALS = 'Invalid login or password.';

    #[Route('/login', name: 'api_auth_login', methods: ['POST'])]
    public function login(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
        UserChecker $userChecker,
        DeviceTokenService $deviceTokens,
        RateLimiterFactory $apiLoginLimiter,
        LoggerInterface $logger
    ): JsonResponse {
        $payload = $request->getPayload();
        $login = trim((string) $payload->get('login', ''));
        $password = (string) $payload->get('password', '');
        $deviceName = trim((string) $payload->get('device_name', ''));
        $platform = (string) $payload->get('platform', 'other');

        $details = [];
        if ($login === '') {
            $details['login'] = ['Login is required'];
        }
        if ($password === '') {
            $details['password'] = ['Password is required'];
        }
        if ($deviceName === '') {
            $details['device_name'] = ['Device name is required'];
        } elseif (mb_strlen($deviceName) > 100) {
            $details['device_name'] = ['Device name must be 100 characters or fewer'];
        }
        if ($details) {
            throw ApiException::validation($details);
        }

        $limit = $apiLoginLimiter->create($request->getClientIp() . '|' . mb_strtolower($login))->consume();
        if (!$limit->isAccepted()) {
            $retry = max(1, $limit->getRetryAfter()->getTimestamp() - time());
            throw new ApiException(429, 'too_many_requests', 'Too many login attempts, try again later.', ['retry_after' => $retry]);
        }

        /** @var UserRepository $users */
        $users = $em->getRepository(User::class);
        /** @var User|null $user */
        $user = $users->loadUserByIdentifier($login);
        if (!$user || !$hasher->isPasswordValid($user, $password)) {
            throw new ApiException(401, 'invalid_credentials', self::INVALID_CREDENTIALS);
        }
        try {
            $userChecker->checkPreAuth($user);
        } catch (AccountStatusException $e) {
            throw new ApiException(403, 'account_disabled', 'This account has been disabled.');
        }

        [$token, $plaintext] = $deviceTokens->issue($user, $deviceName, $platform);
        $logger->info("Device token issued for user {$user->getId()} ({$token->getPlatform()}: {$token->getName()})");

        return $this->json([
            'token' => $plaintext,
            'token_type' => 'Bearer',
            'device' => self::device($token, true),
            'user' => self::user($user),
        ], 201);
    }

    #[Route('/logout', name: 'api_auth_logout', methods: ['POST'])]
    public function logout(CurrentDeviceToken $current, DeviceTokenService $deviceTokens): Response
    {
        $token = $current->get();
        if ($token) {
            $deviceTokens->revokeToken($token);
        }
        return new Response('', 204);
    }

    /** @return array<string, mixed> */
    public static function user(User $user): array
    {
        return [
            'id' => $user->getId(),
            'login' => $user->getLogin(),
            'email' => $user->getEmail(),
            'role' => (int) $user->getRole() === User::ROLE_ADMIN ? 'admin' : 'user',
            'active' => (bool) $user->getActive(),
        ];
    }

    /** @return array<string, mixed> */
    public static function device(DeviceToken $token, bool $current): array
    {
        return [
            'id' => $token->getId(),
            'name' => $token->getName(),
            'platform' => $token->getPlatform(),
            'token_prefix' => $token->getTokenPrefix(),
            'created_at' => $token->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'last_used_at' => $token->getLastUsedAt()?->format(\DateTimeInterface::ATOM),
            'current' => $current,
        ];
    }
}
