<?php

declare(strict_types=1);

namespace CodePower\Mitake\Http;

use CodePower\Mitake\Exception\TransportException;

/**
 * Default {@see HttpClient} implementation built on ext-curl.
 *
 * Enforces TLS 1.2+ as required by the Mitake API.
 */
final class CurlHttpClient implements HttpClient
{
    /**
     * @param int $connectTimeout Connection timeout in seconds.
     * @param int $timeout        Total request timeout in seconds.
     * @param string $userAgent   User-Agent header sent with each request.
     */
    public function __construct(
        private readonly int $connectTimeout = 10,
        private readonly int $timeout = 30,
        private readonly string $userAgent = 'codepower-sms-mitake/1.0'
    ) {}

    public function post(string $url, array $query = [], array $form = [], ?string $rawBody = null): HttpResponse
    {
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . $this->buildQuery($query);
        }

        $body = $rawBody ?? $this->buildQuery($form);
        $contentType = $rawBody !== null ? 'text/plain' : 'application/x-www-form-urlencoded';

        $handle = curl_init();
        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_HTTPHEADER => ['Content-Type: ' . $contentType],
            CURLOPT_USERAGENT => $this->userAgent,
        ]);

        $responseBody = curl_exec($handle);
        if ($responseBody === false) {
            // Do NOT interpolate $url into this message: for SmBulkSend it
            // carries the account credentials in its query string, and exception
            // messages frequently end up in logs. curl_error() never contains it.
            throw new TransportException(
                sprintf('cURL request to Mitake failed (%d): %s', curl_errno($handle), curl_error($handle))
            );
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        $response = new HttpResponse($status, (string) $responseBody);
        if (!$response->isSuccessful()) {
            throw new TransportException(sprintf('Mitake returned HTTP %d.', $status));
        }

        return $response;
    }

    /**
     * @param array<string,string> $params
     */
    private function buildQuery(array $params): string
    {
        $pairs = [];
        foreach ($params as $key => $value) {
            $pairs[] = rawurlencode((string) $key) . '=' . rawurlencode($value);
        }
        return implode('&', $pairs);
    }
}
