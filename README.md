# sms-mitake-php

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

## Testing

```bash
composer test
```

## License

[MIT](LICENSE)
