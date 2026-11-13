<?php

declare(strict_types=1);

namespace IndianOpinion\SignedMailGateway;

/**
 * Wires the hooks. Loading the plugin does nothing else: no option reads, no
 * HTTP, no work on requests that send no mail.
 */
final class Plugin
{
    private static ?self $instance = null;

    private readonly Mailer $mailer;
    private readonly SiteHealth $siteHealth;
    private readonly Cli $cli;

    /**
     * @param (callable(Config): GatewayClient)|null $clientFactory
     * @param (callable(): int)|null                 $clock
     */
    private function __construct(private readonly Logger $logger, ?callable $clientFactory = null, ?callable $clock = null)
    {
        $factory = $clientFactory !== null
            ? \Closure::fromCallable($clientFactory)
            : fn (Config $config): GatewayClient => new GatewayClient($config, $this->logger);
        $this->mailer = new Mailer($logger, $factory);
        $this->siteHealth = new SiteHealth($factory, $clock);
        $this->cli = new Cli($factory, $clock);
    }

    /**
     * Registers every hook once. Later calls return the same instance.
     *
     * @param (callable(Config): GatewayClient)|null $clientFactory For tests: how to make a gateway client.
     * @param (callable(): int)|null                 $clock         For tests: Unix time source.
     */
    public static function boot(?Logger $logger = null, ?callable $clientFactory = null, ?callable $clock = null): self
    {
        if (self::$instance !== null) {
            return self::$instance;
        }
        $plugin = new self($logger ?? new Logger(), $clientFactory, $clock);
        self::$instance = $plugin;

        add_filter('wp_mail', [$plugin->mailer, 'filterAtts'], PHP_INT_MAX);
        add_filter('pre_wp_mail', [$plugin->mailer, 'preWpMail'], 10, 2);
        add_action('phpmailer_init', [$plugin->mailer, 'applyProfileToCore'], PHP_INT_MAX);
        add_filter('site_status_tests', [$plugin->siteHealth, 'register']);

        if (defined('WP_CLI') && constant('WP_CLI') && class_exists('WP_CLI')) {
            \WP_CLI::add_command('signed-mail-gateway', $plugin->cli);
        }

        return $plugin;
    }

    public static function instance(): self
    {
        return self::$instance ?? self::boot();
    }

    /** For tests: forget the instance. Hooks already added stay added. */
    public static function reset(): void
    {
        self::$instance = null;
    }

    public function mailer(): Mailer
    {
        return $this->mailer;
    }

    public function siteHealth(): SiteHealth
    {
        return $this->siteHealth;
    }

    public function cli(): Cli
    {
        return $this->cli;
    }

    public function logger(): Logger
    {
        return $this->logger;
    }
}
