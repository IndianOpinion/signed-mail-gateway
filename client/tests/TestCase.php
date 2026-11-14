<?php

declare(strict_types=1);

namespace IndianOpinion\SignedMailGateway\Tests;

use IndianOpinion\SignedMailGateway\Config;
use IndianOpinion\SignedMailGateway\GatewayClient;
use IndianOpinion\SignedMailGateway\Logger;
use IndianOpinion\SignedMailGateway\Plugin;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public const URL = 'https://gateway.example.org';
    public const SECRET = 'client-test-secret-0123456789abcdef-XYZ';
    public const NOW = 1767225600;

    /** @var list<string> */
    protected array $logs = [];
    protected Plugin $plugin;
    protected int $now = self::NOW;

    protected function setUp(): void
    {
        parent::setUp();
        \WpStubs::reset();
        \WP_CLI::reset();
        Plugin::reset();
        $this->logs = [];
        $this->now = self::NOW;
        $logger = new Logger(function (string $line): void {
            $this->logs[] = $line;
        });
        $this->plugin = Plugin::boot(
            $logger,
            fn (Config $config): GatewayClient => new GatewayClient($config, $logger, fn (): int => $this->now),
            fn (): int => $this->now
        );
    }

    protected function tearDown(): void
    {
        Plugin::reset();
        parent::tearDown();
    }

    protected function configure(string $url = self::URL, string $secret = self::SECRET): void
    {
        add_filter('signed_mail_gateway_config', static fn (): array => ['url' => $url, 'secret' => $secret]);
        update_option(Config::OPTION_DOMAINS, 'mail.example.org');
        update_option(Config::OPTION_DEFAULT_FROM, 'News <news@mail.example.org>');
    }

    /**
     * Every gateway call answers with this status and body.
     *
     * @param array<mixed>|string $body
     */
    protected function respond(int $status, array|string $body): void
    {
        $raw = is_array($body) ? (string) json_encode($body) : $body;
        \WpStubs::$http = static fn (): array => ['response' => ['code' => $status], 'body' => $raw];
    }

    /**
     * Answers in turn from a queue of [status, body] pairs.
     *
     * @param list<array{0: int, 1: array<mixed>|string}> $queue
     */
    protected function respondQueue(array $queue): void
    {
        \WpStubs::$http = static function () use (&$queue): array {
            $next = array_shift($queue) ?? [500, 'queue empty'];
            $raw = is_array($next[1]) ? (string) json_encode($next[1]) : $next[1];

            return ['response' => ['code' => $next[0]], 'body' => $raw];
        };
    }

    protected function transportError(string $message): void
    {
        \WpStubs::$http = static fn (): \WP_Error => new \WP_Error('http_request_failed', $message);
    }

    protected function sentOk(string $id = 'msg-1'): void
    {
        $this->respond(200, ['ok' => true, 'id' => $id, 'domain' => 'mail.example.org', 'dropped_headers' => []]);
    }

    /**
     * The decoded JSON body of the n-th gateway request.
     *
     * @return array<string, mixed>
     */
    protected function payload(int $n = 0): array
    {
        $body = \WpStubs::$requests[$n]['args']['body'] ?? '';
        $decoded = json_decode(is_string($body) ? $body : '', true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function attemptLines(): array
    {
        $out = [];
        foreach ($this->logs as $line) {
            $decoded = json_decode(substr($line, strlen(Logger::PREFIX)), true);
            if (is_array($decoded) && ($decoded['event'] ?? '') === 'attempt') {
                $out[] = $decoded;
            }
        }

        return $out;
    }

    /**
     * @return list<mixed>
     */
    protected function actionArgs(string $tag): array
    {
        $out = [];
        foreach (\WpStubs::$actions as [$name, $args]) {
            if ($name === $tag) {
                $out[] = $args;
            }
        }

        return $out;
    }
}
