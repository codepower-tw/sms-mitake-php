<?php

declare(strict_types=1);

namespace CodePower\Mitake\Http;

/**
 * Minimal HTTP transport abstraction so the Mitake client can be tested
 * without real network access. Implement this to plug in your own client.
 */
interface HttpClient
{
    /**
     * Perform an HTTP POST.
     *
     * @param string $url The absolute endpoint URL.
     * @param array<string,string> $query Query-string parameters appended to the URL.
     * @param array<string,string> $form  Form parameters sent as
     *        application/x-www-form-urlencoded. Values are raw bytes already
     *        encoded in the target charset; the transport URL-encodes them.
     * @param string|null $rawBody When non-null, sent verbatim as the request
     *        body (used for SmBulkSend) and $form is ignored.
     *
     * @throws \CodePower\Mitake\Exception\TransportException on connection/TLS
     *         failure or a non-2xx HTTP status.
     */
    public function post(string $url, array $query = [], array $form = [], ?string $rawBody = null): HttpResponse;
}
