<?php

declare(strict_types=1);

namespace CodePower\Mitake\Tests\Support;

use CodePower\Mitake\Http\HttpClient;
use CodePower\Mitake\Http\HttpResponse;

/**
 * Test double that records requests and returns a canned response body.
 *
 * @phpstan-type Call array{url:string, query:array<string,string>, form:array<string,string>, rawBody:string|null}
 */
final class FakeHttpClient implements HttpClient
{
    /** @var list<array{url:string, query:array<string,string>, form:array<string,string>, rawBody:string|null}> */
    public array $calls = [];

    public function __construct(
        private readonly string $responseBody = '',
        private readonly int $statusCode = 200
    ) {
    }

    public function post(string $url, array $query = [], array $form = [], ?string $rawBody = null): HttpResponse
    {
        $this->calls[] = ['url' => $url, 'query' => $query, 'form' => $form, 'rawBody' => $rawBody];

        return new HttpResponse($this->statusCode, $this->responseBody);
    }

    /**
     * @return array{url:string, query:array<string,string>, form:array<string,string>, rawBody:string|null}
     */
    public function lastCall(): array
    {
        if ($this->calls === []) {
            throw new \LogicException('No request was made.');
        }

        return $this->calls[array_key_last($this->calls)];
    }
}
