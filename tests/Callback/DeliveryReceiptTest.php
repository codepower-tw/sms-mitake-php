<?php

declare(strict_types=1);

namespace CodePower\Mitake\Tests\Callback;

use CodePower\Mitake\Callback\DeliveryReceipt;
use CodePower\Mitake\Exception\InvalidMessageException;
use PHPUnit\Framework\TestCase;

final class DeliveryReceiptTest extends TestCase
{
    public function testParsesCallbackParams(): void
    {
        $receipt = DeliveryReceipt::fromArray([
            'msgid' => '8091234567',
            'dstaddr' => '0912345678',
            'dlvtime' => '20240618101500',
            'donetime' => '20240618101530',
            'statuscode' => '4',
            'statusstr' => 'DELIVRD',
            'StatusFlag' => '4',
        ]);

        $this->assertSame('8091234567', $receipt->msgId);
        $this->assertSame('0912345678', $receipt->recipient);
        $this->assertSame('DELIVRD', $receipt->statusString);
        $this->assertTrue($receipt->isDelivered());
        $this->assertTrue($receipt->isFinal());
        $this->assertSame('20240618101530', $receipt->doneAt?->format('YmdHis'));
        $this->assertSame('20240618101500', $receipt->scheduledAt?->format('YmdHis'));
    }

    public function testFallsBackToStatusFlag(): void
    {
        $receipt = DeliveryReceipt::fromArray(['msgid' => '1', 'StatusFlag' => '8']);
        $this->assertSame('8', $receipt->statusCode->code);
        $this->assertTrue($receipt->isFinal());
        $this->assertFalse($receipt->isDelivered());
    }

    public function testMissingMsgidThrows(): void
    {
        $this->expectException(InvalidMessageException::class);
        DeliveryReceipt::fromArray(['statuscode' => '4']);
    }

    public function testAcknowledgementFormat(): void
    {
        $this->assertSame(
            "magicid=sms_gateway_rpack\nmsgid=8091234567\n",
            DeliveryReceipt::acknowledgementFor('8091234567')
        );
    }

    public function testInstanceAcknowledge(): void
    {
        $receipt = DeliveryReceipt::fromArray(['msgid' => '42', 'statuscode' => '1']);
        $this->assertSame("magicid=sms_gateway_rpack\nmsgid=42\n", $receipt->acknowledge());
    }
}
