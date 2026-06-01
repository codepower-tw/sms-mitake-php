<?php

declare(strict_types=1);

namespace CodePower\Mitake\Result;

use CodePower\Mitake\StatusCode;

/**
 * Outcome of sending one message (one `[n]` block of an SmSend / SmBulkSend response).
 */
final class SendResult
{
    /**
     * @param string|null $msgId Mitake message serial (msgid); null if the send failed.
     * @param StatusCode $statusCode Send status code.
     * @param int|null $accountPoint Remaining account credit after this send (AccountPoint).
     * @param bool $duplicate True if Mitake treated this as a duplicate of a prior clientid (Duplicate=Y).
     * @param int|null $smsPoint Points charged for this message (only when smsPointFlag was set).
     * @param string|null $clientId Echoed client message id, when one was supplied.
     */
    public function __construct(
        public readonly ?string $msgId,
        public readonly StatusCode $statusCode,
        public readonly ?int $accountPoint = null,
        public readonly bool $duplicate = false,
        public readonly ?int $smsPoint = null,
        public readonly ?string $clientId = null
    ) {}

    /** The message was accepted by Mitake (has a serial and no error code). */
    public function isAccepted(): bool
    {
        return $this->msgId !== null && $this->msgId !== '' && !$this->statusCode->isError();
    }
}
