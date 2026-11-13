<?php

declare(strict_types=1);

namespace IndianOpinion\SignedMailGateway;

/**
 * One Site Health test: runs `/v1/check`, cached for five minutes.
 *
 * Good when the check passes, recommended when it passes but the clocks
 * differ by more than half the gateway's skew, critical when it fails or the
 * gateway is the provider but not configured.
 */
final class SiteHealth
{
    public const TRANSIENT = 'signed_mail_gateway_health';
    public const CACHE_SECONDS = 300;
    public const TEST = 'signed_mail_gateway';

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
     * `site_status_tests` filter.
     *
     * @param mixed $tests
     *
     * @return mixed
     */
    public function register($tests)
    {
        if (!is_array($tests)) {
            return $tests;
        }
        $tests['direct'][self::TEST] = [
            'label' => __('Signed mail gateway', 'signed-mail-gateway'),
            'test' => [$this, 'run'],
        ];

        return $tests;
    }

    /**
     * @return array{label: string, status: string, badge: array{label: string, color: string},
     *     description: string, actions: string, test: string}
     */
    public function run(): array
    {
        if (!current_user_can('view_site_health_checks')) {
            return $this->report('recommended', __('Signed mail gateway status is hidden', 'signed-mail-gateway'), '');
        }
        $config = Config::load();
        if ($config->provider === Config::PROVIDER_WORDPRESS) {
            return $this->report(
                'good',
                __('Mail is sent by WordPress; the signed mail gateway is turned off', 'signed-mail-gateway'),
                __('The provider option is set to wordpress.', 'signed-mail-gateway')
            );
        }
        if (!$config->isConfigured()) {
            return $this->report(
                'critical',
                __('The signed mail gateway is not configured', 'signed-mail-gateway'),
                sprintf(
                    /* translators: %s: a configuration problem code such as missing_secret. */
                    __('Problem: %s. Set the gateway URL and secret in wp-config.php.', 'signed-mail-gateway'),
                    (string) $config->problem
                )
            );
        }

        $cached = get_transient(self::TRANSIENT);
        if (!is_array($cached)) {
            $cached = $this->probe($config);
            set_transient(self::TRANSIENT, $cached, self::CACHE_SECONDS);
        }

        if (($cached['ok'] ?? false) !== true) {
            return $this->report(
                'critical',
                __('The signed mail gateway check fails', 'signed-mail-gateway'),
                sprintf(
                    /* translators: 1: result code, 2: result class. */
                    __('The gateway check returned %1$s (%2$s). Mail falls back to WordPress where that is safe.', 'signed-mail-gateway'),
                    is_string($cached['code'] ?? null) ? $cached['code'] : 'unknown',
                    is_string($cached['class'] ?? null) ? $cached['class'] : 'unknown'
                )
            );
        }

        $diff = is_int($cached['clock_diff'] ?? null) ? $cached['clock_diff'] : 0;
        $skew = is_int($cached['skew'] ?? null) ? $cached['skew'] : 300;
        if (abs($diff) * 2 > $skew) {
            return $this->report(
                'recommended',
                __('The site clock differs from the signed mail gateway clock', 'signed-mail-gateway'),
                sprintf(
                    /* translators: 1: seconds, 2: the gateway's allowed skew in seconds. */
                    __(
                        'The clocks differ by %1$d seconds; the gateway refuses requests over %2$d seconds. Check NTP on this server.',
                        'signed-mail-gateway'
                    ),
                    abs($diff),
                    $skew
                )
            );
        }

        return $this->report(
            'good',
            __('The signed mail gateway is working', 'signed-mail-gateway'),
            sprintf(
                /* translators: %s: current or next. */
                __('The check passed with the %s key.', 'signed-mail-gateway'),
                is_string($cached['key_slot'] ?? null) ? $cached['key_slot'] : 'current'
            )
        );
    }

    /**
     * @return array{ok: bool, class: string, code: string, key_slot: string, clock_diff: int, skew: int}
     */
    private function probe(Config $config): array
    {
        $result = ($this->clientFactory)($config)->check();
        $server = $result->serverTime;
        $skew = $result->reply['max_skew_seconds'] ?? 300;

        return [
            'ok' => $result->isSent(),
            'class' => $result->class,
            'code' => $result->code,
            'key_slot' => is_string($result->reply['key_slot'] ?? null) ? $result->reply['key_slot'] : '',
            'clock_diff' => $server !== null ? ($this->clock)() - $server : 0,
            'skew' => is_int($skew) && $skew > 0 ? $skew : 300,
        ];
    }

    /**
     * @return array{label: string, status: string, badge: array{label: string, color: string},
     *     description: string, actions: string, test: string}
     */
    private function report(string $status, string $label, string $detail): array
    {
        return [
            'label' => $label,
            'status' => $status,
            'badge' => ['label' => __('Email', 'signed-mail-gateway'), 'color' => $status === 'good' ? 'blue' : 'red'],
            'description' => '<p>' . esc_html($detail) . '</p>',
            'actions' => '',
            'test' => self::TEST,
        ];
    }
}
