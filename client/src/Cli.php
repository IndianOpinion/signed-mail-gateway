<?php

declare(strict_types=1);

namespace IndianOpinion\SignedMailGateway;

/**
 * Inspect and test the signed mail gateway client.
 *
 * Never prints the secret.
 */
final class Cli
{
    /** @var \Closure(Config): GatewayClient */
    private \Closure $clientFactory;

    /** @var \Closure(): int */
    private \Closure $clock;

    /**
     * @param callable(Config): GatewayClient $clientFactory
     * @param (callable(): int)|null           $clock
     */
    public function __construct(callable $clientFactory, ?callable $clock = null)
    {
        $this->clientFactory = \Closure::fromCallable($clientFactory);
        $this->clock = $clock !== null ? \Closure::fromCallable($clock) : static fn (): int => time();
    }

    /**
     * Shows the configuration and the result of a gateway check.
     *
     * ## EXAMPLES
     *
     *     wp signed-mail-gateway status
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function status(array $args = [], array $assoc = []): void
    {
        $config = Config::load();
        \WP_CLI::line('provider:      ' . $config->provider);
        \WP_CLI::line('fallback:      ' . ($config->fallback ? 'on' : 'off'));
        \WP_CLI::line('configured:    ' . ($config->isConfigured() ? 'yes' : 'no (' . (string) $config->problem . ')'));
        \WP_CLI::line('url:           ' . ($config->url !== '' ? $config->url : '(none)'));
        \WP_CLI::line('domains:       ' . ($config->domains !== [] ? implode(', ', $config->domains) : '(none)'));
        \WP_CLI::line('default from:  ' . ($config->defaultFrom !== '' ? $config->defaultFrom : '(none)'));
        foreach ($config->warnings as $warning) {
            \WP_CLI::warning($warning);
        }
        $result = ($this->clientFactory)($config)->check();
        \WP_CLI::line('check:         ' . $result->class . ' (' . $result->code . ')');
        if ($result->isSent()) {
            $slot = $result->reply['key_slot'] ?? '';
            \WP_CLI::line('key slot:      ' . (is_string($slot) ? $slot : ''));
        }
        if ($result->serverTime !== null) {
            $diff = ($this->clock)() - $result->serverTime;
            \WP_CLI::line(sprintf('clock:         origin %d s %s', abs($diff), $diff >= 0 ? 'fast' : 'slow'));
        }
    }

    /**
     * Runs only the gateway check. Exit code 0 on success, 1 otherwise.
     *
     * ## EXAMPLES
     *
     *     wp signed-mail-gateway check
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function check(array $args = [], array $assoc = []): void
    {
        $result = ($this->clientFactory)(Config::load())->check();
        if ($result->isSent()) {
            $slot = $result->reply['key_slot'] ?? '';
            \WP_CLI::success('gateway check passed, key slot ' . (is_string($slot) ? $slot : ''));

            return;
        }
        \WP_CLI::error('gateway check failed: ' . $result->code . ' (' . $result->class . ')');
    }

    /**
     * Sends one short plain-text message through the normal wp_mail() path.
     *
     * ## OPTIONS
     *
     * <address>
     * : Where to send the test message.
     *
     * ## EXAMPLES
     *
     *     wp signed-mail-gateway send-test you@example.org
     *
     * @subcommand send-test
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function sendTest(array $args = [], array $assoc = []): void
    {
        $address = $args[0] ?? '';
        if (!Address::isValid($address)) {
            \WP_CLI::error('give one valid address');
        }
        $ok = wp_mail(
            $address,
            'signed-mail-gateway test',
            "This is a test message from the signed-mail-gateway plugin.\n"
        );
        $last = Plugin::instance()->mailer()->lastResult();
        $line = sprintf(
            'class %s, path %s, code %s, id %s',
            is_string($last['class'] ?? null) ? $last['class'] : '?',
            is_string($last['path'] ?? null) ? $last['path'] : '?',
            is_string($last['code'] ?? null) ? $last['code'] : '?',
            is_string($last['id'] ?? null) && $last['id'] !== '' ? $last['id'] : '(none)'
        );
        if ($ok) {
            \WP_CLI::success($line);

            return;
        }
        \WP_CLI::error($line);
    }
}
