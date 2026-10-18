<?php

namespace App\Exception;

/** A rename or custom-URL change that cannot be applied, with a stable reason for API mapping. */
final class FileEditException extends \RuntimeException
{
    public const INVALID_NAME = 'invalid_name';
    public const ALIAS_INVALID = 'alias_invalid';
    public const ALIAS_TAKEN = 'alias_taken';
    public const NO_OWNER = 'no_owner';
    public const HISTORY_NOT_FOUND = 'history_not_found';

    public function __construct(public readonly string $reason, string $message, public readonly ?string $field = null)
    {
        parent::__construct($message);
    }

    public static function invalidName(string $message): self
    {
        return new self(self::INVALID_NAME, $message, 'base_name');
    }

    public static function historyNotFound(): self
    {
        return new self(self::HISTORY_NOT_FOUND, 'Name history entry not found');
    }
}
