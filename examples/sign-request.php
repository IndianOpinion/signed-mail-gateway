<?php

/**
 * Builds and prints a signed request with plain PHP, then prints a curl
 * command that sends it. No WordPress needed.
 *
 *   php examples/sign-request.php https://mail-gateway.example.org "$SECRET" check
 *   php examples/sign-request.php https://mail-gateway.example.org "$SECRET" send you@example.com
 *
 * Running the printed curl command twice shows the replay guard: the second
 * run gets 409 replay.
 */

declare(strict_types=1);

require __DIR__ . '/../client/bootstrap.php';

use IndianOpinion\SignedMailGateway\GatewayClient;
use IndianOpinion\SignedMailGateway\Signer;

[$script, $url, $secret, $route, $to] = $argv + [null, '', '', 'check', ''];
if ($url === '' || $secret === '') {
    fwrite(STDERR, "usage: php {$script} <gateway-url> <secret> check|send [to-address]\n");
    exit(2);
}

if ($route === 'send') {
    $path = GatewayClient::ROUTE_SEND;
    $body = GatewayClient::encode([
        'from' => 'news@mail.example.org',
        'to' => [$to],
        'subject' => 'Gateway test',
        'text' => 'Sent with examples/sign-request.php.',
    ]);
} else {
    $path = GatewayClient::ROUTE_CHECK;
    $body = '{}';
}

$headers = Signer::headers($secret, $body, time());
$cmd = 'curl -sS -X POST ' . escapeshellarg(rtrim($url, '/') . $path)
    . ' -H ' . escapeshellarg('Content-Type: application/json');
foreach ($headers as $name => $value) {
    $cmd .= ' -H ' . escapeshellarg($name . ': ' . $value);
}
$cmd .= ' --data-binary ' . escapeshellarg($body);

echo $cmd, "\n";
