<?php

declare(strict_types=1);

namespace CodePower\Mitake;

/**
 * Character set used to encode message content on the wire.
 *
 * Mitake defaults to Big5; this client defaults to UTF-8 for convenience.
 * The value is what Mitake expects in its CharsetURL / Encoding_PostIn fields.
 */
enum Charset: string
{
    case UTF8 = 'UTF8';
    case Big5 = 'Big5';

    /**
     * Encode a UTF-8 string into the byte representation this charset expects.
     *
     * @throws Exception\MitakeException if Big5 is requested without ext-mbstring.
     */
    public function encode(string $utf8): string
    {
        if ($this === self::UTF8) {
            return $utf8;
        }
        if (!function_exists('mb_convert_encoding')) {
            throw new Exception\MitakeException(
                'The mbstring extension is required to send messages using the Big5 charset.'
            );
        }
        return mb_convert_encoding($utf8, 'BIG-5', 'UTF-8');
    }

    /**
     * Decode a string in this charset back to UTF-8.
     */
    public function decode(string $bytes): string
    {
        if ($this === self::UTF8 || !function_exists('mb_convert_encoding')) {
            return $bytes;
        }
        return mb_convert_encoding($bytes, 'UTF-8', 'BIG-5');
    }
}
