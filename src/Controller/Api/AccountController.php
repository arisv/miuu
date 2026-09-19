<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Exception\ApiException;
use App\Repository\UserRepository;
use App\Security\CurrentDeviceToken;
use App\Service\DeviceTokenService;
use App\Service\PurgeNotCancellable;
use App\Service\PurgeService;
use App\Service\UserService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/v1')]
class AccountController extends AbstractController
{
    public function __construct(
        private UserService $users,
        private DeviceTokenService $deviceTokens,
        private UserPasswordHasherInterface $hasher,
        private ValidatorInterface $validator,
        private EntityManagerInterface $em
    ) {
    }

    #[Route('/me', name: 'api_me', methods: ['GET'])]
    public function me(#[CurrentUser] User $user): JsonResponse
    {
        return $this->json(['user' => AuthController::user($user)]);
    }

    #[Route('/me/email', name: 'api_me_email', methods: ['POST'])]
    public function changeEmail(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $payload = $request->getPayload();
        $email = trim((string) $payload->get('email', ''));
        $this->requireCurrentPassword($user, (string) $payload->get('current_password', ''));

        $details = $this->violations('email', $email, [new Assert\NotBlank(), new Assert\Email()]);
        /** @var UserRepository $repo */
        $repo = $this->em->getRepository(User::class);
        if (!$details && $repo->isIdentifierTaken($email, $user->getId())) {
            $details['email'] = ['Email already in use'];
        }
        if ($details) {
            throw ApiException::validation($details);
        }
        $this->users->changeEmail($user, $email);
        return $this->json(['user' => AuthController::user($user)]);
    }

    #[Route('/me/password', name: 'api_me_password', methods: ['POST'])]
    public function changePassword(Request $request, #[CurrentUser] User $user, CurrentDeviceToken $current): JsonResponse
    {
        $payload = $request->getPayload();
        $new = (string) $payload->get('new_password', '');
        $this->requireCurrentPassword($user, (string) $payload->get('current_password', ''));

        $details = $this->violations('new_password', $new, [
            new Assert\NotBlank(),
            new Assert\Length(min: 6, max: 4096, minMessage: 'Your password should be at least {{ limit }} characters'),
        ]);
        if ($details) {
            throw ApiException::validation($details);
        }
        $this->users->changePassword($user, $new);
        $revoked = 0;
        if ($payload->getBoolean('revoke_other_devices')) {
            $revoked = $this->deviceTokens->revokeOthers($user, $current->get());
        }
        return $this->json(['user' => AuthController::user($user), 'revoked_devices' => $revoked]);
    }

    /** Purge state: how many files the user has, how many are queued for deletion, whether a purge can be cancelled. */
    #[Route('/me/purge', name: 'api_me_purge_status', methods: ['GET'])]
    public function purgeStatus(#[CurrentUser] User $user, PurgeService $purge): JsonResponse
    {
        return $this->json(['purge' => $purge->status($user)]);
    }

    /** Queues every file of the user for deletion. Requires the current password. */
    #[Route('/me/purge', name: 'api_me_purge', methods: ['POST'])]
    public function requestPurge(Request $request, #[CurrentUser] User $user, PurgeService $purge): JsonResponse
    {
        $this->requireCurrentPassword($user, (string) $request->getPayload()->get('current_password', ''));
        $marked = $purge->purge($user, 'the user (app)');
        return $this->json(['marked' => $marked, 'purge' => $purge->status($user)]);
    }

    /** Restores every file while the purge is still pending (all files marked). */
    #[Route('/me/purge', name: 'api_me_purge_cancel', methods: ['DELETE'])]
    public function cancelPurge(#[CurrentUser] User $user, PurgeService $purge): JsonResponse
    {
        try {
            $restored = $purge->cancel($user, 'the user (app)');
        } catch (PurgeNotCancellable $e) {
            throw ApiException::badRequest('purge_not_cancellable', $e->getMessage());
        }
        return $this->json(['restored' => $restored, 'purge' => $purge->status($user)]);
    }

    #[Route('/devices', name: 'api_devices_list', methods: ['GET'])]
    public function devices(#[CurrentUser] User $user, CurrentDeviceToken $current): JsonResponse
    {
        $currentId = $current->get()?->getId();
        $out = [];
        foreach ($this->deviceTokens->listActive($user) as $token) {
            $out[] = AuthController::device($token, $token->getId() === $currentId);
        }
        return $this->json(['devices' => $out]);
    }

    #[Route('/devices/{id}', name: 'api_devices_revoke', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function revokeDevice(int $id, #[CurrentUser] User $user): Response
    {
        try {
            $this->deviceTokens->revoke($user, $id);
        } catch (\Exception $e) {
            throw ApiException::notFound('Device not found');
        }
        return new Response('', 204);
    }

    private function requireCurrentPassword(User $user, string $password): void
    {
        if ($password === '' || !$this->hasher->isPasswordValid($user, $password)) {
            throw ApiException::validation(['current_password' => ['Current password is incorrect']]);
        }
    }

    /**
     * @param list<\Symfony\Component\Validator\Constraint> $constraints
     * @return array<string, string[]>
     */
    private function violations(string $field, mixed $value, array $constraints): array
    {
        $messages = [];
        foreach ($this->validator->validate($value, $constraints) as $violation) {
            $messages[] = (string) $violation->getMessage();
        }
        return $messages ? [$field => $messages] : [];
    }
}
