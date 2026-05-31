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
}
