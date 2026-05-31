<?php

declare(strict_types=1);

namespace CodePower\Mitake\Tests;

use CodePower\Mitake\StatusCode;
use PHPUnit\Framework\TestCase;

final class StatusCodeTest extends TestCase
{
    public function testDeliveredCode(): void
    {
        $code = new StatusCode('4');
        $this->assertTrue($code->isDelivered());
        $this->assertFalse($code->isError());
        $this->assertFalse($code->isFailed());
        $this->assertSame('已送達手機', $code->description());
    }

    public function testPendingCodes(): void
    {
        foreach (['0', '1', '2'] as $c) {
            $this->assertTrue((new StatusCode($c))->isPending(), "code $c should be pending");
        }
    }

    public function testFailedCodes(): void
    {
        foreach (['5', '6', '7', '8'] as $c) {
            $this->assertTrue((new StatusCode($c))->isFailed(), "code $c should be failed");
        }
    }

    public function testCancelledCode(): void
    {
        $this->assertTrue((new StatusCode('9'))->isCancelled());
    }

    public function testLetterCodesAreErrors(): void
    {
        $code = new StatusCode('e');
        $this->assertTrue($code->isError());
        $this->assertFalse($code->isDelivered());
        $this->assertSame('帳號、密碼錯誤', $code->description());
    }

    public function testAsteriskIsError(): void
    {
        $this->assertTrue((new StatusCode('*'))->isError());
    }

    public function testUnknownCodeHasNoDescription(): void
    {
        $this->assertNull((new StatusCode('Q'))->description());
    }

    public function testStringable(): void
    {
        $this->assertSame('4', (string) new StatusCode('4'));
    }
}
