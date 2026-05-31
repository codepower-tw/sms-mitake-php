<?php

declare(strict_types=1);

namespace CodePower\Mitake;

/**
 * An outbound SMS message.
 *
 * Maps to the Mitake SmSend / SmBulkSend request fields. Times are passed as
 * {@see \DateTimeInterface} and formatted to Mitake's YYYYMMDDHHMMSS on the wire.
 */
final class Message
{
    /**
     * @param string $to Recipient mobile number, e.g. "0912345678" (dstaddr).
     * @param string $body Message content (smbody).
     * @param string|null $destName Recipient name / source-system key (destname).
     * @param \DateTimeInterface|null $deliverAt Scheduled send time (dlvtime); null = send now.
     * @param \DateTimeInterface|null $validUntil Validity deadline (vldtime); null = Mitake default (24h).
     * @param string|null $callbackUrl Delivery-receipt callback URL (response).
     * @param string|null $clientId Client message id for de-duplication (clientid); REQUIRED for bulk sends.
     * @param string|null $objectId Batch name (objectID), single-send only.
     * @throws Exception\InvalidMessageException if recipient or body is empty.
     */
    public function __construct(
        public readonly string $to,
        public readonly string $body,
        public readonly ?string $destName = null,
        public readonly ?\DateTimeInterface $deliverAt = null,
        public readonly ?\DateTimeInterface $validUntil = null,
        public readonly ?string $callbackUrl = null,
        public readonly ?string $clientId = null,
        public readonly ?string $objectId = null
    ) {
        if (trim($to) === '') {
            throw new Exception\InvalidMessageException('Message recipient (to) must not be empty.');
        }
        if ($body === '') {
            throw new Exception\InvalidMessageException('Message body must not be empty.');
        }
    }

    /**
     * Return a copy with the client id set (used to assign bulk ids).
     */
    public function withClientId(string $clientId): self
    {
        return new self(
            $this->to,
            $this->body,
            $this->destName,
            $this->deliverAt,
            $this->validUntil,
            $this->callbackUrl,
            $clientId,
            $this->objectId
        );
    }
}
