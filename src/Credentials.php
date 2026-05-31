<?php

declare(strict_types=1);

namespace CodePower\Mitake;

/**
 * Immutable Mitake API credentials.
 */
final class Credentials
{
    /**
     * @param string $username Mitake account username (max 20).
     * @param string $password Mitake account password (max 24).
     * @throws Exception\InvalidMessageException if either value is empty.
     */
    public function __construct(
        public readonly string $username,
        public readonly string $password
    ) {
        if ($username === '') {
            throw new Exception\InvalidMessageException('Mitake username must not be empty.');
        }
        if ($password === '') {
            throw new Exception\InvalidMessageException('Mitake password must not be empty.');
        }
    }
}
