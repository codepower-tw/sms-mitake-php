<?php

declare(strict_types=1);

namespace CodePower\Mitake\Result;

/**
 * Remaining account credit (Mitake AccountPoint), expressed in points.
 */
final class Balance
{
    public function __construct(public readonly int $points)
    {
    }
}
