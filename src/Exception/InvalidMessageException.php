<?php

declare(strict_types=1);

namespace CodePower\Mitake\Exception;

/**
 * Thrown when a message or request is invalid before it is sent
 * (e.g. empty recipient/body, or a bulk message missing its client id).
 */
class InvalidMessageException extends \InvalidArgumentException implements MitakeExceptionInterface {}
