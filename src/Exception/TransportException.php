<?php

declare(strict_types=1);

namespace CodePower\Mitake\Exception;

/**
 * Thrown when the HTTP transport fails to reach Mitake or returns a
 * non-success HTTP status (connection error, timeout, TLS failure, 5xx, …).
 */
class TransportException extends MitakeException
{
}
