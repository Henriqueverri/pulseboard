<?php

namespace App\Exceptions;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class IngestionException extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $errors
     */
    private function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $httpStatus,
        public readonly array $errors = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    public static function validation(string $message, string $code, array $errors): self
    {
        return new self($message, $code, Response::HTTP_UNPROCESSABLE_ENTITY, $errors);
    }

    public static function conflict(string $message, string $code): self
    {
        return new self($message, $code, Response::HTTP_CONFLICT);
    }

    /**
     * A resource of another organization is indistinguishable from a missing one.
     */
    public static function notFound(string $message): self
    {
        return new self($message, 'not_found', Response::HTTP_NOT_FOUND);
    }
}
