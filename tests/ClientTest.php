<?php

declare(strict_types=1);

namespace CodePower\Mitake\Tests;

use CodePower\Mitake\Charset;
use CodePower\Mitake\Client;
use CodePower\Mitake\Credentials;
use CodePower\Mitake\Exception\InvalidMessageException;
use CodePower\Mitake\Message;
use CodePower\Mitake\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    private function client(FakeHttpClient $http, Charset $charset = Charset::UTF8): Client
    {
        return new Client(new Credentials('user', 'pass'), $charset, $http, 'https://example.test');
    }

    public function testSendBuildsRequestAndParsesResult(): void
    {
        $http = new FakeHttpClient("[1]\nmsgid=0000000013\nstatuscode=1\nAccountPoint=126\n");
        $result = $this->client($http)->send(new Message('0912345678', 'Hello'));

        $call = $http->lastCall();
        $this->assertSame('https://example.test/api/mtk/SmSend', $call['url']);
        $this->assertSame('UTF8', $call['query']['CharsetURL']);
        $this->assertSame('user', $call['form']['username']);
        $this->assertSame('pass', $call['form']['password']);
        $this->assertSame('0912345678', $call['form']['dstaddr']);
        $this->assertSame('Hello', $call['form']['smbody']);
        $this->assertArrayNotHasKey('smsPointFlag', $call['form']);

        $this->assertSame('0000000013', $result->msgId);
        $this->assertSame(126, $result->accountPoint);
        $this->assertTrue($result->isAccepted());
    }

    public function testSendEncodesOptionalFieldsAndFlag(): void
    {
        $http = new FakeHttpClient("[c1]\nmsgid=1\nstatuscode=1\n");
        $message = new Message(
            to: '0912345678',
            body: 'Hi',
            destName: 'Wang',
            deliverAt: new \DateTimeImmutable('2024-06-18 10:15:00'),
            validUntil: new \DateTimeImmutable('2024-06-19 10:15:00'),
            callbackUrl: 'https://cb.test/hook',
            clientId: 'c1',
            objectId: 'batchA',
        );
        $this->client($http)->send($message, returnSmsPoint: true);

        $form = $http->lastCall()['form'];
        $this->assertSame('Wang', $form['destname']);
        $this->assertSame('20240618101500', $form['dlvtime']);
        $this->assertSame('20240619101500', $form['vldtime']);
        $this->assertSame('https://cb.test/hook', $form['response']);
        $this->assertSame('c1', $form['clientid']);
        $this->assertSame('batchA', $form['objectID']);
        $this->assertSame('1', $form['smsPointFlag']);
    }

    public function testSendConvertsNewlinesToAsciiSix(): void
    {
        $http = new FakeHttpClient("[1]\nmsgid=1\nstatuscode=1\n");
        $this->client($http)->send(new Message('0912345678', "line1\nline2"));

        $this->assertSame('line1' . chr(6) . 'line2', $http->lastCall()['form']['smbody']);
    }

    public function testBulkSendBuildsRecordsAndQuery(): void
    {
        $http = new FakeHttpClient("[a]\nmsgid=1\nstatuscode=1\n[b]\nmsgid=2\nstatuscode=1\n");
        $messages = [
            new Message('0900000001', 'Hi A', clientId: 'a'),
            new Message('0900000002', 'Hi B', clientId: 'b'),
        ];
        $results = $this->client($http)->sendBulk($messages, objectId: 'batchX');

        $call = $http->lastCall();
        $this->assertSame('https://example.test/api/mtk/SmBulkSend', $call['url']);
        $this->assertSame('user', $call['query']['username']);
        $this->assertSame('UTF8', $call['query']['Encoding_PostIn']);
        $this->assertSame('batchX', $call['query']['objectID']);
        $this->assertSame([], $call['form']);

        $records = explode("\n", (string) $call['rawBody']);
        $this->assertCount(2, $records);
        $fields = explode('$$', $records[0]);
        $this->assertCount(7, $fields);
        $this->assertSame('a', $fields[0]);
        $this->assertSame('0900000001', $fields[1]);
        $this->assertSame('Hi A', $fields[6]);

        $this->assertCount(2, $results);
        $this->assertSame('a', $results[0]->clientId);
    }

    public function testBulkSendRequiresClientId(): void
    {
        $http = new FakeHttpClient('');
        $this->expectException(InvalidMessageException::class);
        $this->client($http)->sendBulk([new Message('0900000001', 'Hi')]);
    }

    public function testBulkSendRejectsEmpty(): void
    {
        $http = new FakeHttpClient('');
        $this->expectException(InvalidMessageException::class);
        $this->client($http)->sendBulk([]);
    }

    public function testQueryStatusJoinsIdsAndParses(): void
    {
        $http = new FakeHttpClient("0311216947\t4\t20060623103807\n");
        $results = $this->client($http)->queryStatus(['0311216947', '0311216948']);

        $this->assertSame('0311216947,0311216948', $http->lastCall()['form']['msgid']);
        $this->assertSame('https://example.test/api/mtk/SmQuery', $http->lastCall()['url']);
        $this->assertTrue($results[0]->statusCode->isDelivered());
    }

    public function testQueryStatusRejectsTooMany(): void
    {
        $http = new FakeHttpClient('');
        $this->expectException(InvalidMessageException::class);
        $this->client($http)->queryStatus(array_fill(0, 101, '1'));
    }

    public function testQueryBalanceOmitsMsgid(): void
    {
        $http = new FakeHttpClient('AccountPoint=110');
        $balance = $this->client($http)->queryBalance();

        $this->assertArrayNotHasKey('msgid', $http->lastCall()['form']);
        $this->assertSame(110, $balance->points);
    }

    public function testCancelParsesResults(): void
    {
        $http = new FakeHttpClient("0311216947=9\n0311216948=5\n");
        $results = $this->client($http)->cancel(['0311216947', '0311216948']);

        $this->assertSame('https://example.test/api/mtk/SmCancel', $http->lastCall()['url']);
        $this->assertSame('0311216947,0311216948', $http->lastCall()['form']['msgid']);
        $this->assertTrue($results[0]->isCancelled());
        $this->assertFalse($results[1]->isCancelled());
    }

    public function testBig5SendEncodesBody(): void
    {
        if (!function_exists('mb_convert_encoding')) {
            $this->markTestSkipped('mbstring not available');
        }
        $http = new FakeHttpClient("[1]\nmsgid=1\nstatuscode=1\n");
        $this->client($http, Charset::Big5)->send(new Message('0912345678', '測試'));

        $call = $http->lastCall();
        $this->assertSame('Big5', $call['query']['CharsetURL']);
        $this->assertSame(mb_convert_encoding('測試', 'BIG-5', 'UTF-8'), $call['form']['smbody']);
    }
}
