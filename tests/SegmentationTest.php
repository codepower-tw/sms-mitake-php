<?php

declare(strict_types=1);

namespace CodePower\Mitake\Tests;

use CodePower\Mitake\Message;
use CodePower\Mitake\Segmentation;
use CodePower\Mitake\SmsEncoding;
use PHPUnit\Framework\TestCase;

final class SegmentationTest extends TestCase
{
    public function testPlainAsciiUsesGsmAndCountsOnePerChar(): void
    {
        $s = Segmentation::measure('Hello');

        $this->assertSame(SmsEncoding::Gsm7, $s->encoding);
        $this->assertSame(5, $s->length);
        $this->assertSame(1, $s->segments);
        $this->assertSame(155, $s->remaining);
        $this->assertFalse($s->isMultipart());
    }

    public function testGsmExtensionSymbolsCountAsTwo(): void
    {
        // "Hi {x}" => H i space { x }  =>  1+1+1+2+1+2 = 8
        $s = Segmentation::measure('Hi {x}');

        $this->assertSame(SmsEncoding::Gsm7, $s->encoding);
        $this->assertSame(8, $s->length);
        $this->assertSame(1, $s->segments);
    }

    public function testEuroSignIsGsmExtension(): void
    {
        $s = Segmentation::measure('€');

        $this->assertSame(SmsEncoding::Gsm7, $s->encoding);
        $this->assertSame(2, $s->length);
    }

    public function testChineseUsesUcs2(): void
    {
        $s = Segmentation::measure('測試');

        $this->assertSame(SmsEncoding::Ucs2, $s->encoding);
        $this->assertSame(2, $s->length);
        $this->assertSame(1, $s->segments);
        $this->assertSame(68, $s->remaining);
    }

    public function testEmojiCountsAsTwoUcs2Units(): void
    {
        $s = Segmentation::measure('😀');

        $this->assertSame(SmsEncoding::Ucs2, $s->encoding);
        $this->assertSame(2, $s->length);
        $this->assertSame(1, $s->segments);
    }

    public function testAnyNonGsmCharForcesUcs2ForWholeMessage(): void
    {
        // 'a' is GSM, '測' is not; the whole message becomes UCS-2, 'a' now 1 unit.
        $s = Segmentation::measure('a測');

        $this->assertSame(SmsEncoding::Ucs2, $s->encoding);
        $this->assertSame(2, $s->length);
    }

    public function testLineBreaksCountAsOneUnit(): void
    {
        $s = Segmentation::measure("a\r\nb");

        $this->assertSame(SmsEncoding::Gsm7, $s->encoding);
        $this->assertSame(3, $s->length);
        $this->assertSame(1, $s->segments);
    }

    public function testGsmSingleSegmentBoundary(): void
    {
        $this->assertSame(1, Segmentation::measure(str_repeat('a', 160))->segments);

        $s = Segmentation::measure(str_repeat('a', 161));
        $this->assertSame(2, $s->segments);
        $this->assertSame(161, $s->length);
        $this->assertTrue($s->isMultipart());
    }

    public function testGsmConcatenatedSegments(): void
    {
        // 153 units per part once concatenated.
        $this->assertSame(2, Segmentation::measure(str_repeat('a', 306))->segments);
        $this->assertSame(3, Segmentation::measure(str_repeat('a', 307))->segments);
    }

    public function testUcs2SingleSegmentBoundary(): void
    {
        $this->assertSame(1, Segmentation::measure(str_repeat('測', 70))->segments);
        $this->assertSame(2, Segmentation::measure(str_repeat('測', 71))->segments);
    }

    public function testUcs2ConcatenatedSegments(): void
    {
        // 67 units per part once concatenated.
        $this->assertSame(2, Segmentation::measure(str_repeat('測', 134))->segments);
        $this->assertSame(3, Segmentation::measure(str_repeat('測', 135))->segments);
    }

    public function testEmptyBodyIsZeroSegments(): void
    {
        $s = Segmentation::measure('');

        $this->assertSame(0, $s->length);
        $this->assertSame(0, $s->segments);
        $this->assertFalse($s->isMultipart());
    }

    public function testMessageConvenienceMeasuresItsBody(): void
    {
        $s = (new Message('0912345678', '測試'))->segmentation();

        $this->assertSame(SmsEncoding::Ucs2, $s->encoding);
        $this->assertSame(2, $s->length);
    }
}
