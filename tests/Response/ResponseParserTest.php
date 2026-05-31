<?php

declare(strict_types=1);

namespace CodePower\Mitake\Tests\Response;

use CodePower\Mitake\Exception\ResponseException;
use CodePower\Mitake\Response\ResponseParser;
use PHPUnit\Framework\TestCase;

final class ResponseParserTest extends TestCase
{
    private ResponseParser $parser;

    protected function setUp(): void
    {
        $this->parser = new ResponseParser();
    }

    public function testParseSingleSend(): void
    {
        $body = "[1]\r\nmsgid=#0000000013\r\nstatuscode=1\r\nAccountPoint=126\r\n";
        $results = $this->parser->parseSendResults($body);

        $this->assertCount(1, $results);
        $this->assertSame('0000000013', $results[0]->msgId); // leading '#' stripped
        $this->assertSame('1', $results[0]->statusCode->code);
        $this->assertSame(126, $results[0]->accountPoint);
        $this->assertSame('1', $results[0]->clientId);
        $this->assertTrue($results[0]->isAccepted());
    }

    public function testParseBulkSendBlocks(): void
    {
        $body = "[1]\nmsgid=0000000333\nstatuscode=0\n[2]\nmsgid=0000000334\nstatuscode=1\nAccountPoint=92\n";
        $results = $this->parser->parseSendResults($body);

        $this->assertCount(2, $results);
        $this->assertSame('0000000333', $results[0]->msgId);
        $this->assertSame('1', $results[0]->clientId); // clientId is the bracket value
        $this->assertSame('2', $results[1]->clientId);
        $this->assertSame(92, $results[1]->accountPoint);
    }

    public function testParseSendDuplicate(): void
    {
        $body = "[abc]\nmsgid=123\nstatuscode=1\nDuplicate=Y\n";
        $results = $this->parser->parseSendResults($body);
        $this->assertTrue($results[0]->duplicate);
        $this->assertSame('abc', $results[0]->clientId);
    }

    public function testParseStatusRows(): void
    {
        $body = "0311216947\t6\t20060623103807\r\n0311216948\t4\t20060623103810\r\n";
        $results = $this->parser->parseStatusResults($body);

        $this->assertCount(2, $results);
        $this->assertSame('0311216947', $results[0]->msgId);
        $this->assertSame('6', $results[0]->statusCode->code);
        $this->assertInstanceOf(\DateTimeImmutable::class, $results[0]->statusTime);
        $this->assertSame('20060623103807', $results[0]->statusTime->format('YmdHis'));
        $this->assertTrue($results[1]->statusCode->isDelivered());
    }

    public function testParseStatusWithSmsPoint(): void
    {
        $body = "0311216947\t4\t20060623103807\t1\n";
        $results = $this->parser->parseStatusResults($body);
        $this->assertSame(1, $results[0]->smsPoint);
    }

    public function testParseBalance(): void
    {
        $this->assertSame(110, $this->parser->parseBalance("AccountPoint=110")->points);
    }

    public function testParseCancel(): void
    {
        $body = "0311216947=9\r\n0311216948=?\r\n0311216949=5\r\n";
        $results = $this->parser->parseCancelResults($body);

        $this->assertCount(3, $results);
        $this->assertTrue($results[0]->isCancelled());
        $this->assertFalse($results[2]->isCancelled());
        $this->assertSame('?', $results[1]->statusCode->code);
    }

    public function testStatusQueryErrorTokenThrows(): void
    {
        $this->expectException(ResponseException::class);
        $this->parser->parseStatusResults("w");
    }

    public function testBalanceErrorTokenThrows(): void
    {
        $this->expectException(ResponseException::class);
        $this->parser->parseBalance("e");
    }

    public function testEmptySendResponseThrows(): void
    {
        $this->expectException(ResponseException::class);
        $this->parser->parseSendResults('   ');
    }
}
