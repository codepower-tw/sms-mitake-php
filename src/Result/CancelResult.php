<?php

declare(strict_types=1);

namespace CodePower\Mitake\Result;

use CodePower\Mitake\StatusCode;

/**
 * Outcome of cancelling one scheduled message (one line of an SmCancel response).
 */
final class CancelResult
{
    /**
     * @param string $msgId Message serial (msgid).
     * @param StatusCode $statusCode Result code: 9 = cancelled, 0 = cancel failed,
     *        1-8 = current (un-cancellable) status, ? = unknown/foreign msgid.
     */
    public function __construct(
        public readonly string $msgId,
        public readonly StatusCode $statusCode
    ) {}

    /** The scheduled message was successfully cancelled (status code 9). */
    public function isCancelled(): bool
    {
        return $this->statusCode->code === StatusCode::CANCELLED;
    }
}
