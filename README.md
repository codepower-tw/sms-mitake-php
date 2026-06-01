# sms-mitake-php

[![CI](https://github.com/codepower-tw/sms-mitake-php/actions/workflows/ci.yml/badge.svg)](https://github.com/codepower-tw/sms-mitake-php/actions/workflows/ci.yml)
[![Security](https://github.com/codepower-tw/sms-mitake-php/actions/workflows/security.yml/badge.svg)](https://github.com/codepower-tw/sms-mitake-php/actions/workflows/security.yml)

A PHP client for the Mitake (三竹簡訊) SMS HTTP API.

Covers single send, bulk send, delivery-status and balance queries,
cancellation of scheduled messages, and parsing the delivery-receipt callback.

## Requirements

- PHP >= 8.1
- ext-curl
- ext-mbstring (only if you send using the Big5 charset)

## Installation

```bash
composer require codepower/sms-mitake
```

## Usage

```php
use CodePower\Mitake\Client;
use CodePower\Mitake\Credentials;
use CodePower\Mitake\Message;

$client = new Client(new Credentials('username', 'password'));
```

By default the client talks to `https://smsapi.mitake.com.tw` using UTF-8. Pass a
`Charset` and/or base URL to the constructor to change either, and inject your own
`Http\HttpClient` to customise transport or for testing.

### Send one message

```php
$result = $client->send(new Message(to: '0912345678', body: 'Hello 你好'));

$result->msgId;                 // Mitake message serial, e.g. "0000000013"
$result->isAccepted();          // true if accepted
$result->statusCode->code;      // raw status code
$result->accountPoint;          // remaining credit after this send
```

Optional fields — scheduling, validity, a delivery-receipt callback URL, and a
de-duplication client id:

```php
$client->send(new Message(
    to: '0912345678',
    body: 'See you tomorrow',
    deliverAt: new DateTimeImmutable('2026-06-02 09:00:00'),
    validUntil: new DateTimeImmutable('2026-06-02 12:00:00'),
    callbackUrl: 'https://example.com/mitake/callback',
    clientId: 'order-4821',
));
```

### Send in bulk (up to 500)

Each bulk message **must** carry a `clientId`:

```php
$results = $client->sendBulk([
    new Message('0912345678', 'Hi A', clientId: 'a'),
    new Message('0987654321', 'Hi B', clientId: 'b'),
]);
```

### Check length & segments

Estimate how a body will be billed and split before sending. GSM extension
symbols (`^ { } \ [ ~ ] | €`) count as two characters, and any non-GSM character
(Chinese, emoji, …) switches the whole message to the UCS-2 tier:

```php
$seg = (new Message('0912345678', 'Hello 你好'))->segmentation();

$seg->encoding;      // SmsEncoding::Ucs2 (a Chinese char forces UCS-2)
$seg->length;        // billed unit count
$seg->segments;      // number of SMS parts
$seg->isMultipart(); // true if more than one part
$seg->remaining;     // free units left in the last part

// Or measure any string directly:
\CodePower\Mitake\Segmentation::measure('plain ascii')->encoding; // SmsEncoding::Gsm7
```

Per-segment sizes are the carrier standard: 160/153 for GSM, 70/67 for UCS-2.

### Query delivery status / balance

```php
$statuses = $client->queryStatus(['0000000013', '0000000014']);
foreach ($statuses as $status) {
    $status->statusCode->isDelivered();
    $status->statusTime;   // DateTimeImmutable|null
}

$balance = $client->queryBalance();   // $balance->points
```

### Cancel scheduled messages

```php
foreach ($client->cancel(['0000000013']) as $cancel) {
    $cancel->isCancelled();   // true when status code is 9
}
```

### Handle the delivery-receipt callback

Mitake calls your `callbackUrl` with the latest status. Parse the request and
reply with the acknowledgement body so Mitake stops retrying:

```php
use CodePower\Mitake\Callback\DeliveryReceipt;

$receipt = DeliveryReceipt::fromArray($_GET);

$receipt->msgId;
$receipt->isDelivered();
$receipt->isFinal();

header('Content-Type: text/plain');
echo $receipt->acknowledge();   // "magicid=sms_gateway_rpack\nmsgid=...\n"
```

## Security

**Bulk send credentials travel in the URL.** `SmBulkSend` carries its record
payload as the request body, so Mitake requires the `username` and `password` in
the URL query string (every other call sends them in the POST body). TLS protects
them in transit, but query strings are routinely written to access logs and
proxies. Make sure any HTTP request logging on your side does not capture full
Mitake URLs for `sendBulk` calls. This library never includes the request URL in
its exception messages.

## Testing

```bash
composer test
```

## License

[MIT](LICENSE) © CodePower Ltd. — applies to this library's own source code.

The Mitake API specification bundled under [`docs/`](docs/) is © Mitake Inc.
(三竹資訊股份有限公司), all rights reserved, and is redistributed from Mitake's
public [download page](https://sms.mitake.com.tw/common/header/download.jsp) for
convenience. It is **not** covered by the MIT license.
