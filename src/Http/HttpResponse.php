<?php

declare(strict_types=1);

namespace CodePower\Mitake\Http;

/**
 * Immutable HTTP response: the status code and raw body returned by Mitake.
 */
final class HttpResponse
{
    public function __construct(
        public readonly int $statusCode,
        public readonly string $body
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }
}
