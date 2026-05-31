<?php

declare(strict_types=1);

namespace CodePower\Mitake\Exception;

/**
 * Marker interface implemented by every exception this library throws,
 * so callers can catch all of them with a single catch block.
 */
interface MitakeExceptionInterface extends \Throwable
{
}
