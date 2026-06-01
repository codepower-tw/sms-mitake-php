<?php

/**
 * Runnable example for the codepower/sms-mitake client.
 *
 * Pass your Mitake credentials (and, optionally, a recipient) on the command
 * line or via environment variables, then run it:
 *
 *   php example.php <username> <password> [recipient] [message]
 *
 *   # or with environment variables
 *   MITAKE_USERNAME=xxx MITAKE_PASSWORD=yyy MITAKE_TO=0912345678 php example.php
 *
 * With no recipient it only queries your remaining credit (free, and a good way
 * to confirm the credentials work). Give a recipient mobile number to also send
 * a real test message — that consumes one SMS point.
 */

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use CodePower\Mitake\Client;
use CodePower\Mitake\Credentials;
use CodePower\Mitake\Exception\MitakeExceptionInterface;
use CodePower\Mitake\Message;

// --- Read credentials from CLI arguments, falling back to environment variables.
$username = $argv[1] ?? getenv('MITAKE_USERNAME') ?: null;
$password = $argv[2] ?? getenv('MITAKE_PASSWORD') ?: null;
$recipient = $argv[3] ?? getenv('MITAKE_TO') ?: null;
$body = $argv[4] ?? getenv('MITAKE_BODY') ?: 'Hello from sms-mitake 你好';

if ($username === null || $password === null) {
    fwrite(STDERR, <<<USAGE
        Missing credentials.

        Usage:
          php example.php <username> <password> [recipient] [message]

        Or set environment variables:
          MITAKE_USERNAME, MITAKE_PASSWORD, MITAKE_TO (optional), MITAKE_BODY (optional)

        Without a recipient this only checks your account balance (no charge).

        USAGE);
    exit(1);
}

$client = new Client(new Credentials($username, $password));

try {
    // 1. Query remaining credit. This is free and verifies the credentials.
    $balance = $client->queryBalance();
    echo "Account balance: {$balance->points} points" . PHP_EOL;

    if ($recipient === null) {
        echo 'No recipient given — skipping send. Pass a mobile number to send a test SMS.' . PHP_EOL;
        exit(0);
    }

    // 2. Send a single test message.
    echo "Sending to {$recipient}..." . PHP_EOL;
    $result = $client->send(new Message(to: $recipient, body: $body));

    echo '  accepted:    ' . ($result->isAccepted() ? 'yes' : 'no') . PHP_EOL;
    echo '  msgId:       ' . ($result->msgId ?? '(none)') . PHP_EOL;
    echo '  statusCode:  ' . $result->statusCode->code
        . ' (' . ($result->statusCode->description() ?? 'unknown') . ')' . PHP_EOL;
    echo '  creditLeft:  ' . ($result->accountPoint ?? '(unknown)') . PHP_EOL;

    if (!$result->isAccepted()) {
        exit(1);
    }
} catch (MitakeExceptionInterface $e) {
    // Every exception this library throws implements MitakeExceptionInterface,
    // so one catch covers transport failures and unparseable responses alike.
    fwrite(STDERR, 'Mitake error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
