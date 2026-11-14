<?php

declare(strict_types=1);

namespace IndianOpinion\SignedMailGateway\Tests;

final class CliTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function checkReply(int $serverTime = self::NOW): array
    {
        return [
            'ok' => true, 'check' => true, 'key_slot' => 'next', 'server_time' => $serverTime,
            'enabled_domains' => ['mail.example.org'], 'max_recipients' => 10, 'batch_max' => 10,
            'batch_concurrency' => 4, 'max_body_bytes' => 4194304, 'max_skew_seconds' => 300, 'version' => '0.1.0',
        ];
    }

    /** C36: `check` exits 0 on a good check and 1 on a 401. */
    public function testC36CheckExitCodes(): void
    {
        $this->configure();
        $this->respond(200, self::checkReply());
        $this->plugin->cli()->check();
        self::assertSame('Success: gateway check passed, key slot next', end(\WP_CLI::$out));
        self::assertSame(self::URL . '/v1/check', \WpStubs::$requests[0]['url']);
        self::assertSame('{}', \WpStubs::$requests[0]['args']['body']);

        $this->respond(401, ['ok' => false, 'error' => 'auth_failed']);
        try {
            $this->plugin->cli()->check();
            self::fail('expected exit 1');
        } catch (\WpCliExit $e) {
            self::assertSame(1, $e->getCode());
            self::assertStringContainsString('auth_failed', $e->getMessage());
        }
    }

    public function testStatusNeverPrintsTheSecret(): void
    {
        $this->configure();
        $this->respond(200, self::checkReply(self::NOW - 30));
        $this->plugin->cli()->status();
        $out = implode("\n", \WP_CLI::$out);
        self::assertStringNotContainsString(self::SECRET, $out);
        self::assertStringContainsString('provider:      gateway', $out);
        self::assertStringContainsString('configured:    yes', $out);
        self::assertStringContainsString('key slot:      next', $out);
        self::assertStringContainsString('clock:         origin 30 s fast', $out);
    }

    public function testSendTestPrintsClassPathAndId(): void
    {
        $this->configure();
        $this->sentOk('id-42');
        $this->plugin->cli()->sendTest(['you@example.org']);
        self::assertSame('Success: class sent, path gateway, code sent, id id-42', end(\WP_CLI::$out));
    }

    public function testCommandIsRegisteredUnderWpCli(): void
    {
        self::assertSame([], \WP_CLI::$commands, 'not registered outside WP-CLI');
    }
}
