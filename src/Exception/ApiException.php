<?php

namespace App\Exception;

/** Controller-raised API error carrying the HTTP status, a stable machine code and optional details. */
class ApiException extends \RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(
        private int $statusCode,
        private string $errorCode,
        string $message,
        private array $details = []
    ) {
        parent::__construct($message);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /** @return array<string, mixed> */
    public function getDetails(): array
    {
        return $this->details;
    }

    public static function notFound(string $message = 'Not found'): self
    {
        return new self(404, 'not_found', $message);
    }

    public static function badRequest(string $code, string $message): self
    {
        return new self(400, $code, $message);
    }

    /** @param array<string, mixed> $details */
    public static function validation(array $details, string $message = 'Validation failed'): self
    {
        return new self(422, 'validation_failed', $message, $details);
    }
}
