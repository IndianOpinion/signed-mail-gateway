<?php

declare(strict_types=1);

namespace IndianOpinion\SignedMailGateway\Tests;

use IndianOpinion\SignedMailGateway\Config;
use IndianOpinion\SignedMailGateway\Signer;

/**
 * The secret never reaches output: debug dumps, CLI lines, logs or traces.
 */
final class SecretHygieneTest extends TestCase
{
    public function testDebugDumpsOfConfigRedactTheSecret(): void
    {
        $config = new Config(self::URL, self::SECRET);
        $printed = print_r($config, true);
        ob_start();
        var_dump($config);
        $dumped = (string) ob_get_clean();
        foreach ([$printed, $dumped] as $out) {
            self::assertStringNotContainsString(self::SECRET, $out);
            self::assertStringNotContainsString(substr(self::SECRET, 0, 15), $out);
            self::assertStringContainsString('[redacted]', $out);
            self::assertStringContainsString(self::URL, $out);
        }
        self::assertSame(self::SECRET, $config->secret);
    }

    public function testSecretParametersAreMarkedSensitive(): void
    {
        $params = [
            [Signer::class, 'sign', 'secret'],
            [Signer::class, 'headers', 'secret'],
            [Config::class, '__construct', 'secret'],
        ];
        foreach ($params as [$class, $method, $name]) {
            $found = false;
            foreach ((new \ReflectionMethod($class, $method))->getParameters() as $param) {
                if ($param->getName() === $name) {
                    $found = true;
                    self::assertCount(1, $param->getAttributes('SensitiveParameter'), "$class::$method");
                }
            }
            self::assertTrue($found);
        }
    }

    public function testStatusRedactsUserinfoInTheUrlAndNeverPrintsTheSecret(): void
    {
        add_filter(
            'signed_mail_gateway_config',
            static fn (): array => ['url' => 'https://user:pa55word@gw.example.org', 'secret' => self::SECRET]
        );
        $this->plugin->cli()->status();
        $out = implode("\n", \WP_CLI::$out);
        self::assertStringNotContainsString('pa55word', $out);
        self::assertStringNotContainsString(self::SECRET, $out);
        self::assertStringContainsString('https://[redacted]@gw.example.org', $out);
        self::assertStringContainsString('insecure_url', $out);
    }

    public function testSecretIsUsedByteForByteLikeTheWorker(): void
    {
        $padded = self::SECRET . ' ';
        add_filter('signed_mail_gateway_config', static fn (): array => ['url' => self::URL, 'secret' => $padded]);
        self::assertSame($padded, Config::load()->secret);
        self::assertNotSame(
            Signer::sign(self::SECRET, '1767225600', str_repeat('a', 32), '{}'),
            Signer::sign($padded, '1767225600', str_repeat('a', 32), '{}')
        );
    }
}
