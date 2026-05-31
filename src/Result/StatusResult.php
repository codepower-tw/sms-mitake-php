<?php

declare(strict_types=1);

namespace CodePower\Mitake\Result;

use CodePower\Mitake\StatusCode;

/**
 * One row of an SmQuery delivery-status response.
 */
final class StatusResult
{
    /**
     * @param string $msgId Message serial queried (msgid).
     * @param StatusCode $statusCode Current delivery status.
     * @param \DateTimeImmutable|null $statusTime When the status was set (statustime).
     * @param int|null $smsPoint Points charged (only when smsPointFlag was set).
     */
    public function __construct(
        public readonly string $msgId,
        public readonly StatusCode $statusCode,
        public readonly ?\DateTimeImmutable $statusTime = null,
        public readonly ?int $smsPoint = null
    ) {
    }
}
