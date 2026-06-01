<?php

declare(strict_types=1);

namespace CodePower\Mitake;

/**
 * The air-interface encoding a message is sent with, which determines how many
 * characters fit in one SMS segment.
 *
 * This is NOT {@see Charset} (the Big5/UTF-8 encoding of the HTTP request body).
 * Charset only affects transport to Mitake; this is how the carrier encodes the
 * message over the air. The per-segment sizes are the 3GPP standard: Mitake's
 * spec documents the 160 GSM short-SMS figure explicitly (附錄四), and the rest
 * follow the same standard.
 */
enum SmsEncoding: string
{
    /** GSM 03.38 7-bit alphabet. */
    case Gsm7 = 'GSM_7BIT';

    /** UCS-2, used when any character falls outside the GSM alphabet (e.g. Chinese, emoji). */
    case Ucs2 = 'UCS2';

    /** Max billed units in a single (non-concatenated) SMS. */
    public function singleSegmentMax(): int
    {
        return $this === self::Gsm7 ? 160 : 70;
    }

    /** Max billed units per part once the message spans multiple concatenated segments. */
    public function concatenatedSegmentMax(): int
    {
        return $this === self::Gsm7 ? 153 : 67;
    }
}
