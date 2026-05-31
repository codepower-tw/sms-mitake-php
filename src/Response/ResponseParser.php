<?php

declare(strict_types=1);

namespace CodePower\Mitake\Response;

use CodePower\Mitake\Exception\ResponseException;
use CodePower\Mitake\Result\Balance;
use CodePower\Mitake\Result\CancelResult;
use CodePower\Mitake\Result\SendResult;
use CodePower\Mitake\Result\StatusResult;
use CodePower\Mitake\StatusCode;

/**
 * Parses Mitake's plain-text response formats into result value objects.
 */
final class ResponseParser
{
    /**
     * Parse an SmSend / SmBulkSend response.
     *
     * The body is one or more blocks, each headed by the client id in
     * brackets, e.g.:
     *
     *     [1]
     *     msgid=0000000013
     *     statuscode=1
     *     AccountPoint=126
     *
     * @return SendResult[] In response order.
     * @throws ResponseException if nothing parseable is found.
     */
    public function parseSendResults(string $body): array
    {
        $lines = $this->splitLines($body);
        $results = [];
        $current = null;

        foreach ($lines as $line) {
            if (preg_match('/^\[(.*)\]$/', $line, $m) === 1) {
                if ($current !== null) {
                    $results[] = $this->buildSendResult($current);
                }
                $current = ['clientId' => $m[1] !== '' ? $m[1] : null];
                continue;
            }
            [$key, $value] = $this->splitPair($line);
            if ($key === null) {
                continue;
            }
            if ($current === null) {
                // A loose response with no bracket header (e.g. a global error).
                $current = ['clientId' => null];
            }
            $current[$key] = $value;
        }

        if ($current !== null) {
            $results[] = $this->buildSendResult($current);
        }

        if ($results === []) {
            throw new ResponseException('Unparseable Mitake send response: ' . trim($body));
        }

        return $results;
    }

    /**
     * Parse an SmQuery delivery-status response: TAB-separated
     * `msgid<TAB>statuscode<TAB>statustime[<TAB>smsPoint]` rows.
     *
     * @return StatusResult[] In response order.
     * @throws ResponseException on a global error code.
     */
    public function parseStatusResults(string $body): array
    {
        $results = [];
        foreach ($this->splitLines($body) as $line) {
            $fields = explode("\t", $line);
            if (count($fields) < 2) {
                // A lone token is a global error (e.g. 'w' = query over limit).
                $this->assertNotErrorToken($line);
                continue;
            }
            $results[] = new StatusResult(
                $fields[0],
                new StatusCode($fields[1]),
                $this->parseTimestamp($fields[2] ?? null),
                isset($fields[3]) && $fields[3] !== '' ? (int) $fields[3] : null
            );
        }

        return $results;
    }

    /**
     * Parse an SmQuery balance response: `AccountPoint=NNN`.
     *
     * @throws ResponseException if no AccountPoint is present.
     */
    public function parseBalance(string $body): Balance
    {
        if (preg_match('/AccountPoint=(-?\d+)/', $body, $m) === 1) {
            return new Balance((int) $m[1]);
        }
        $this->assertNotErrorToken(trim($body));
        throw new ResponseException('Unparseable Mitake balance response: ' . trim($body));
    }

    /**
     * Parse an SmCancel response: `msgid=statuscode` per line.
     *
     * @return CancelResult[] In response order.
     */
    public function parseCancelResults(string $body): array
    {
        $results = [];
        foreach ($this->splitLines($body) as $line) {
            [$key, $value] = $this->splitPair($line);
            if ($key === null) {
                $this->assertNotErrorToken($line);
                continue;
            }
            $results[] = new CancelResult($key, new StatusCode($value));
        }

        return $results;
    }

    /**
     * @param array<string,string|null> $fields
     */
    private function buildSendResult(array $fields): SendResult
    {
        $msgId = $fields['msgid'] ?? null;
        if ($msgId !== null) {
            $msgId = ltrim($msgId, '#');
        }

        return new SendResult(
            $msgId !== null && $msgId !== '' ? $msgId : null,
            new StatusCode($fields['statuscode'] ?? ''),
            isset($fields['AccountPoint']) ? (int) $fields['AccountPoint'] : null,
            ($fields['Duplicate'] ?? null) === 'Y',
            isset($fields['smsPoint']) && $fields['smsPoint'] !== '' ? (int) $fields['smsPoint'] : null,
            $fields['clientId'] ?? null
        );
    }

    /**
     * @return array{0: string|null, 1: string}
     */
    private function splitPair(string $line): array
    {
        $pos = strpos($line, '=');
        if ($pos === false) {
            return [null, ''];
        }
        return [substr($line, 0, $pos), substr($line, $pos + 1)];
    }

    private function parseTimestamp(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || strlen($value) !== 14) {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('YmdHis', $value);
        return $dt === false ? null : $dt;
    }

    /**
     * @throws ResponseException if the token is a Mitake error code.
     */
    private function assertNotErrorToken(string $token): void
    {
        $token = trim($token);
        if ($token === '') {
            return;
        }
        $code = new StatusCode($token);
        if ($code->isError()) {
            throw new ResponseException(
                sprintf('Mitake returned error code "%s": %s', $token, $code->description() ?? 'unknown error')
            );
        }
    }

    /**
     * @return string[] Non-empty trimmed lines.
     */
    private function splitLines(string $body): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $body) ?: [];
        $out = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $out[] = $line;
            }
        }
        return $out;
    }
}
