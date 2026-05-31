<?php

declare(strict_types=1);

namespace CodePower\Mitake\Tests;

use CodePower\Mitake\Charset;
use PHPUnit\Framework\TestCase;

final class CharsetTest extends TestCase
{
    public function testUtf8EncodeIsIdentity(): void
    {
        $this->assertSame('測試 test', Charset::UTF8->encode('測試 test'));
        $this->assertSame('測試 test', Charset::UTF8->decode('測試 test'));
    }

    public function testBig5RoundTrip(): void
    {
        if (!function_exists('mb_convert_encoding')) {
            $this->markTestSkipped('mbstring not available');
        }
        $utf8 = '測試';
        $big5 = Charset::Big5->encode($utf8);
        $this->assertNotSame($utf8, $big5, 'Big5 bytes should differ from UTF-8');
        $this->assertSame($utf8, Charset::Big5->decode($big5));
    }

    public function testEnumValuesMatchMitakeFields(): void
    {
        $this->assertSame('UTF8', Charset::UTF8->value);
        $this->assertSame('Big5', Charset::Big5->value);
    }
}
