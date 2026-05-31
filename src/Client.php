<?php

declare(strict_types=1);

namespace CodePower\Mitake;

use CodePower\Mitake\Exception\InvalidMessageException;
use CodePower\Mitake\Http\CurlHttpClient;
use CodePower\Mitake\Http\HttpClient;
use CodePower\Mitake\Response\ResponseParser;
use CodePower\Mitake\Result\Balance;
use CodePower\Mitake\Result\CancelResult;
use CodePower\Mitake\Result\SendResult;
use CodePower\Mitake\Result\StatusResult;

/**
 * Client for the Mitake (三竹簡訊) SMS HTTP API.
 *
 * Covers single send (SmSend), bulk send (SmBulkSend), delivery-status and
 * balance queries (SmQuery), and cancellation of scheduled messages (SmCancel).
 */
final class Client
{
    public const DEFAULT_BASE_URL = 'https://smsapi.mitake.com.tw';

    /** Max recipients per SmBulkSend call. */
    private const BULK_LIMIT = 500;
    /** Max message ids per SmQuery / SmCancel call. */
    private const QUERY_LIMIT = 100;

    private readonly HttpClient $httpClient;
    private readonly ResponseParser $parser;
    private readonly string $baseUrl;

    public function __construct(
        private readonly Credentials $credentials,
        private readonly Charset $charset = Charset::UTF8,
        ?HttpClient $httpClient = null,
        string $baseUrl = self::DEFAULT_BASE_URL,
        ?ResponseParser $parser = null
    ) {
        $this->httpClient = $httpClient ?? new CurlHttpClient();
        $this->parser = $parser ?? new ResponseParser();
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * Send a single message (SmSend).
     *
     * @throws Exception\TransportException|Exception\ResponseException
     */
    public function send(Message $message, bool $returnSmsPoint = false): SendResult
    {
        $form = $this->credentialFields();
        $form += $this->messageFields($message);
        if ($returnSmsPoint) {
            $form['smsPointFlag'] = '1';
        }

        $response = $this->httpClient->post(
            $this->endpoint('SmSend'),
            ['CharsetURL' => $this->charset->value],
            $form
        );

        return $this->parser->parseSendResults($response->body)[0];
    }

    /**
     * Send up to 500 messages in one call (SmBulkSend).
     *
     * Every message MUST carry a client id (it heads each result block).
     *
     * @param Message[] $messages
     * @param string|null $objectId Optional batch name (objectID).
     * @return SendResult[] In request order.
     * @throws InvalidMessageException|Exception\TransportException|Exception\ResponseException
     */
    public function sendBulk(array $messages, bool $returnSmsPoint = false, ?string $objectId = null): array
    {
        $messages = array_values($messages);
        $count = count($messages);
        if ($count === 0) {
            throw new InvalidMessageException('sendBulk requires at least one message.');
        }
        if ($count > self::BULK_LIMIT) {
            throw new InvalidMessageException(
                sprintf('sendBulk accepts at most %d messages, %d given.', self::BULK_LIMIT, $count)
            );
        }

        $records = [];
        foreach ($messages as $i => $message) {
            if ($message->clientId === null || $message->clientId === '') {
                throw new InvalidMessageException(
                    sprintf('Bulk message at index %d is missing a clientId (required for SmBulkSend).', $i)
                );
            }
            $records[] = $this->bulkRecord($message);
        }

        $query = $this->credentialFields();
        $query['Encoding_PostIn'] = $this->charset->value;
        if ($objectId !== null) {
            $query['objectID'] = $objectId;
        }
        if ($returnSmsPoint) {
            $query['smsPointFlag'] = '1';
        }

        $response = $this->httpClient->post(
            $this->endpoint('SmBulkSend'),
            $query,
            [],
            implode("\n", $records)
        );

        return $this->parser->parseSendResults($response->body);
    }

    /**
     * Query the delivery status of up to 100 messages (SmQuery).
     *
     * @param string[] $messageIds
     * @return StatusResult[]
     * @throws InvalidMessageException|Exception\TransportException|Exception\ResponseException
     */
    public function queryStatus(array $messageIds, bool $returnSmsPoint = false): array
    {
        $messageIds = array_values($messageIds);
        if ($messageIds === []) {
            throw new InvalidMessageException('queryStatus requires at least one message id.');
        }
        if (count($messageIds) > self::QUERY_LIMIT) {
            throw new InvalidMessageException(
                sprintf('queryStatus accepts at most %d ids, %d given.', self::QUERY_LIMIT, count($messageIds))
            );
        }

        $form = $this->credentialFields();
        $form['msgid'] = implode(',', $messageIds);
        if ($returnSmsPoint) {
            $form['smsPointFlag'] = '1';
        }

        $response = $this->httpClient->post($this->endpoint('SmQuery'), [], $form);

        return $this->parser->parseStatusResults($response->body);
    }

    /**
     * Query remaining account credit (SmQuery with no msgid).
     *
     * @throws Exception\TransportException|Exception\ResponseException
     */
    public function queryBalance(): Balance
    {
        $response = $this->httpClient->post($this->endpoint('SmQuery'), [], $this->credentialFields());

        return $this->parser->parseBalance($response->body);
    }

    /**
     * Cancel up to 100 scheduled messages (SmCancel).
     *
     * @param string[] $messageIds
     * @return CancelResult[]
     * @throws InvalidMessageException|Exception\TransportException|Exception\ResponseException
     */
    public function cancel(array $messageIds): array
    {
        $messageIds = array_values($messageIds);
        if ($messageIds === []) {
            throw new InvalidMessageException('cancel requires at least one message id.');
        }
        if (count($messageIds) > self::QUERY_LIMIT) {
            throw new InvalidMessageException(
                sprintf('cancel accepts at most %d ids, %d given.', self::QUERY_LIMIT, count($messageIds))
            );
        }

        $form = $this->credentialFields();
        $form['msgid'] = implode(',', $messageIds);

        $response = $this->httpClient->post($this->endpoint('SmCancel'), [], $form);

        return $this->parser->parseCancelResults($response->body);
    }

    /**
     * @return array<string,string>
     */
    private function credentialFields(): array
    {
        return [
            'username' => $this->credentials->username,
            'password' => $this->credentials->password,
        ];
    }

    /**
     * SmSend form fields for a message (Chinese fields charset-encoded).
     *
     * @return array<string,string>
     */
    private function messageFields(Message $message): array
    {
        $fields = [
            'dstaddr' => $message->to,
            'smbody' => $this->encodeBody($message->body),
        ];
        if ($message->destName !== null) {
            $fields['destname'] = $this->charset->encode($message->destName);
        }
        if ($message->deliverAt !== null) {
            $fields['dlvtime'] = $message->deliverAt->format('YmdHis');
        }
        if ($message->validUntil !== null) {
            $fields['vldtime'] = $message->validUntil->format('YmdHis');
        }
        if ($message->callbackUrl !== null) {
            $fields['response'] = $message->callbackUrl;
        }
        if ($message->clientId !== null) {
            $fields['clientid'] = $message->clientId;
        }
        if ($message->objectId !== null) {
            $fields['objectID'] = $message->objectId;
        }
        return $fields;
    }

    /**
     * Build one SmBulkSend record:
     * ClientID $$ dstaddr $$ dlvtime $$ vldtime $$ destname $$ response $$ smbody
     */
    private function bulkRecord(Message $message): string
    {
        $fields = [
            (string) $message->clientId,
            $message->to,
            $message->deliverAt?->format('YmdHis') ?? '',
            $message->validUntil?->format('YmdHis') ?? '',
            $message->destName !== null ? $this->charset->encode($message->destName) : '',
            $message->callbackUrl ?? '',
            $this->encodeBody($message->body),
        ];
        return implode('$$', $fields);
    }

    /**
     * Encode message content: newlines become Mitake's ASCII line-break (0x06),
     * then the string is converted to the configured charset.
     */
    private function encodeBody(string $body): string
    {
        $body = str_replace(["\r\n", "\r", "\n"], chr(6), $body);
        return $this->charset->encode($body);
    }

    private function endpoint(string $action): string
    {
        return $this->baseUrl . '/api/mtk/' . $action;
    }
}
