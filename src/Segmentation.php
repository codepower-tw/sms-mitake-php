<?php

declare(strict_types=1);

namespace CodePower\Mitake;

/**
 * How a message body splits into SMS segments, following Mitake's counting
 * rules (附錄四 of the API spec).
 *
 * Counting rules:
 *  - Content within the GSM 03.38 alphabet uses {@see SmsEncoding::Gsm7}.
 *  - The GSM extension symbols (^ { } \ [ ~ ] | €, and form feed) each need a
 *    0x1B escape on the wire, so they count as TWO units. Mitake lists the
 *    eight ASCII ones in 附錄四.
 *  - Any character outside the GSM alphabet (Chinese, emoji, …) forces the
 *    whole message to {@see SmsEncoding::Ucs2}.
 *  - Line breaks (\r\n, \r, \n) each count as one unit, matching the single
 *    0x06 line-break character the client sends ({@see Client}).
 *
 * Segment sizes (160/153 for GSM, 70/67 for UCS-2) are the 3GPP standard used
 * by carriers. This is independent of {@see Charset}: Big5 vs UTF-8 only affects
 * the HTTP transport, not how the carrier segments the SMS.
 */
final class Segmentation
{
    /** GSM 03.38 basic alphabet — one unit each. */
    private const GSM_BASIC
        = '@£$¥èéùìòÇ' . "\n" . 'Øø' . "\r" . 'Åå'
        . 'Δ_ΦΓΛΩΠΨΣΘΞ'
        . 'ÆæßÉ'
        . ' !"#¤%&' . "'" . '()*+,-./'
        . '0123456789:;<=>?'
        . '¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§'
        . '¿abcdefghijklmnopqrstuvwxyzäöñüà';

    /** GSM 03.38 extension symbols — each needs a 0x1B prefix, so two units. */
    private const GSM_EXTENSION = "\f" . '^{}\[~]|€';

    private function __construct(
        public readonly SmsEncoding $encoding,
        /** Total billed units (Mitake's character count). */
        public readonly int $length,
        /** Number of SMS parts the body is sent as (0 for an empty body). */
        public readonly int $segments,
        /** Free units left in the final segment. */
        public readonly int $remaining,
    ) {}

    /**
     * Measure how the given UTF-8 body segments.
     */
    public static function measure(string $body): self
    {
        $body = (string) preg_replace('/\r\n|\r|\n/', "\n", $body);
        $chars = preg_split('//u', $body, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $basic = array_flip(preg_split('//u', self::GSM_BASIC, -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $extension = array_flip(preg_split('//u', self::GSM_EXTENSION, -1, PREG_SPLIT_NO_EMPTY) ?: []);

        $isGsm = true;
        $costs = [];
        foreach ($chars as $char) {
            if (isset($extension[$char])) {
                $costs[] = 2;
            } elseif (isset($basic[$char])) {
                $costs[] = 1;
            } else {
                $isGsm = false;
                break;
            }
        }

        if ($isGsm) {
            $encoding = SmsEncoding::Gsm7;
        } else {
            $encoding = SmsEncoding::Ucs2;
            // UCS-2 counts UTF-16 code units: astral characters (4-byte UTF-8) use two.
            $costs = array_map(static fn(string $char): int => strlen($char) === 4 ? 2 : 1, $chars);
        }

        $total = array_sum($costs);
        if ($total === 0) {
            return new self($encoding, 0, 0, 0);
        }

        $single = $encoding->singleSegmentMax();
        if ($total <= $single) {
            return new self($encoding, $total, 1, $single - $total);
        }

        // Multi-part: pack greedily into concatenated segments. A two-unit symbol
        // never straddles a boundary, so a part may close one unit early.
        $concat = $encoding->concatenatedSegmentMax();
        $segments = 1;
        $used = 0;
        foreach ($costs as $cost) {
            if ($used + $cost > $concat) {
                $segments++;
                $used = 0;
            }
            $used += $cost;
        }

        return new self($encoding, $total, $segments, $concat - $used);
    }

    /** True when the body spans more than one SMS segment. */
    public function isMultipart(): bool
    {
        return $this->segments > 1;
    }
}
