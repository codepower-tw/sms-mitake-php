<?php

declare(strict_types=1);

namespace CodePower\Mitake\Exception;

/**
 * Thrown when Mitake returns a body that cannot be parsed into the
 * expected shape (malformed or unexpected response).
 */
class ResponseException extends MitakeException
{
}
