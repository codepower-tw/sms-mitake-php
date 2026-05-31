<?php

declare(strict_types=1);

namespace CodePower\Mitake\Callback;

use CodePower\Mitake\Exception\InvalidMessageException;
use CodePower\Mitake\StatusCode;

/**
 * A parsed Mitake delivery-receipt callback (主動狀態回報).
 *
 * Mitake calls your `response` URL with the latest status of a sent message.
 * Build one from the request parameters with {@see self::fromArray()}, then
 * reply with {@see self::acknowledge()} (HTTP 200, text/plain) so Mitake stops
 * retrying.
 */
final class DeliveryReceipt
{
    /** Fixed magic id Mitake expects in the acknowledgement body. */
    public const MAGIC_ID = 'sms_gateway_rpack';

    /**
     * @param array<string,mixed> $raw Original callback parameters.
     */
    public function __construct(
        public readonly string $msgId,
        public readonly StatusCode $statusCode,
        public readonly ?string $recipient = null,
        public readonly ?string $statusString = null,
        public readonly ?\DateTimeImmutable $scheduledAt = null,
        public readonly ?\DateTimeImmutable $doneAt = null,
        public readonly array $raw = []
    ) {
    }

    /**
     * Build a receipt from callback request parameters (e.g. $_GET).
     *
     * @param array<string,mixed> $params
     * @throws InvalidMessageException if the required msgid is missing.
     */
    public static function fromArray(array $params): self
    {
        $msgId = isset($params['msgid']) ? (string) $params['msgid'] : '';
        if ($msgId === '') {
            throw new InvalidMessageException('Delivery receipt callback is missing the msgid parameter.');
        }

        $code = (string) ($params['statuscode'] ?? $params['StatusFlag'] ?? '');

        return new self(
            $msgId,
            new StatusCode($code),
            isset($params['dstaddr']) ? (string) $params['dstaddr'] : null,
            isset($params['statusstr']) ? (string) $params['statusstr'] : null,
            self::parseTime($params['dlvtime'] ?? null),
            self::parseTime($params['donetime'] ?? null),
            $params
        );
    }

    public function isDelivered(): bool
    {
        return $this->statusCode->isDelivered();
    }

    /** True once the status is terminal (delivered, failed, expired, cancelled). */
    public function isFinal(): bool
    {
        return $this->statusCode->isDelivered()
            || $this->statusCode->isFailed()
            || $this->statusCode->isCancelled();
    }

    /**
     * The body to return to Mitake to acknowledge this receipt.
     */
    public function acknowledge(): string
    {
        return self::acknowledgementFor($this->msgId);
    }

    /**
     * The acknowledgement body for a given message id.
     */
    public static function acknowledgementFor(string $msgId): string
    {
        return 'magicid=' . self::MAGIC_ID . "\nmsgid=" . $msgId . "\n";
    }

    private static function parseTime(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }
        $value = (string) $value;
        if (strlen($value) !== 14) {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('YmdHis', $value);
        return $dt === false ? null : $dt;
    }
}
