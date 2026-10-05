<?php

namespace App\Exceptions;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * An insights request that cannot be answered, with a stable `code` for the
 * front (rendered in bootstrap/app.php). Messages never carry prompt or model
 * content.
 */
final class AiException extends RuntimeException
{
    public const DISABLED = 'ai_disabled';

    public const NOT_ENABLED = 'ai_not_enabled';

    public const QUOTA_EXCEEDED = 'ai_quota_exceeded';

    public const PROVIDER_UNAVAILABLE = 'ai_provider_unavailable';

    public const TIMEOUT = 'ai_timeout';

    public const INVALID_OUTPUT = 'ai_invalid_output';

    private function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $httpStatus,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    public static function disabled(): self
    {
        return new self('Insights are currently unavailable.', self::DISABLED, Response::HTTP_SERVICE_UNAVAILABLE);
    }

    public static function notEnabled(): self
    {
        return new self('Insights are not enabled for this organization.', self::NOT_ENABLED, Response::HTTP_FORBIDDEN);
    }

    public static function quotaExceeded(int $retryAfter): self
    {
        return new self('The insights quota has been reached.', self::QUOTA_EXCEEDED, Response::HTTP_TOO_MANY_REQUESTS, max(1, $retryAfter));
    }

    public static function providerUnavailable(): self
    {
        return new self('The AI provider is unavailable. Try again later.', self::PROVIDER_UNAVAILABLE, Response::HTTP_SERVICE_UNAVAILABLE);
    }

    public static function timeout(): self
    {
        return new self('The AI provider did not answer in time.', self::TIMEOUT, Response::HTTP_GATEWAY_TIMEOUT);
    }

    public static function invalidOutput(): self
    {
        return new self('The AI answer could not be validated.', self::INVALID_OUTPUT, Response::HTTP_BAD_GATEWAY);
    }
}
