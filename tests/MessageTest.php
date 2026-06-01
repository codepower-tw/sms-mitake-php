<?php

declare(strict_types=1);

namespace CodePower\Mitake\Tests;

use CodePower\Mitake\Exception\InvalidMessageException;
use CodePower\Mitake\Message;
use PHPUnit\Framework\TestCase;

final class MessageTest extends TestCase
{
    public function testRejectsEmptyRecipient(): void
    {
        $this->expectException(InvalidMessageException::class);
        new Message('  ', 'hi');
    }

    public function testRejectsEmptyBody(): void
    {
        $this->expectException(InvalidMessageException::class);
        new Message('0912345678', '');
    }

    public function testWithClientIdReturnsCopy(): void
    {
        $a = new Message('0912345678', 'hi');
        $b = $a->withClientId('abc');

        $this->assertNull($a->clientId);
        $this->assertSame('abc', $b->clientId);
        $this->assertNotSame($a, $b);
        $this->assertSame('0912345678', $b->to);
        $this->assertSame('hi', $b->body);
    }

    // A line break in a structural field could inject a whole new SmBulkSend
    // record (an arbitrary recipient + body) into a batch; "$$" could smuggle
    // extra positional fields. Both must be rejected at construction.

    public function testRejectsLineBreakInRecipient(): void
    {
        $this->expectException(InvalidMessageException::class);
        new Message("0912345678\n0900000000", 'hi');
    }

    public function testRejectsCarriageReturnInRecipient(): void
    {
        $this->expectException(InvalidMessageException::class);
        new Message("0912345678\r0900000000", 'hi');
    }

    public function testRejectsLineBreakInDestName(): void
    {
        $this->expectException(InvalidMessageException::class);
        new Message('0912345678', 'hi', destName: "Eve\n0900000000");
    }

    public function testRejectsLineBreakInClientId(): void
    {
        $this->expectException(InvalidMessageException::class);
        new Message('0912345678', 'hi', clientId: "c1\n0900000000");
    }

    public function testRejectsLineBreakInCallbackUrl(): void
    {
        $this->expectException(InvalidMessageException::class);
        new Message('0912345678', 'hi', callbackUrl: "https://cb\n0900000000");
    }

    public function testRejectsBulkDelimiterInRecipient(): void
    {
        $this->expectException(InvalidMessageException::class);
        new Message('0912345678$$0900000000', 'hi');
    }

    public function testRejectsBulkDelimiterInDestName(): void
    {
        $this->expectException(InvalidMessageException::class);
        new Message('0912345678', 'hi', destName: 'Eve$$0900000000$$spam');
    }

    public function testRejectsBulkDelimiterInClientId(): void
    {
        $this->expectException(InvalidMessageException::class);
        new Message('0912345678', 'hi', clientId: 'c1$$x');
    }

    /**
     * Body line breaks are legitimate (converted to Mitake's 0x06 on the wire),
     * so the structural-field guard must not extend to the body.
     */
    public function testAllowsLineBreaksInBody(): void
    {
        $message = new Message('0912345678', "line1\nline2");
        $this->assertSame("line1\nline2", $message->body);
    }
}
