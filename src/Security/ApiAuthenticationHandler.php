<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccountStatusException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/** Every unauthenticated or failed /api/ request answers with the same JSON error shape. */
class ApiAuthenticationHandler implements AuthenticationEntryPointInterface, AuthenticationFailureHandlerInterface
{
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return $this->error(401, 'unauthenticated', 'Authentication required: send "Authorization: Bearer <device token>".');
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        if ($exception instanceof AccountStatusException) {
            return $this->error(403, 'account_disabled', 'This account has been disabled.');
        }
        return $this->error(401, 'unauthenticated', 'Invalid or revoked device token.');
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message]], $status, [
            'WWW-Authenticate' => 'Bearer realm="MIU"',
        ]);
    }
}
