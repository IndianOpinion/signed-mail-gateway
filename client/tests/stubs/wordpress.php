<?php

/**
 * A small stand-in for the parts of WordPress the client uses, so PHPUnit
 * runs without a WordPress install. Behaviour follows core where it matters
 * (hook priorities, wp_mail()'s filter order and events).
 */

declare(strict_types=1);

// phpcs:disable PSR1.Files.SideEffects, PSR1.Classes.ClassDeclaration, Squiz.Classes.ValidClassName

use PHPMailer\PHPMailer\PHPMailer;

final class WpStubs
{
    /** @var array<string, array<int, list<array{0: callable, 1: int}>>> */
    public static array $hooks = [];
    /** @var array<int, array<string, mixed>> */
    public static array $options = [];
    /** @var array<int, array<string, mixed>> */
    public static array $transients = [];
    /** @var list<array{url: string, args: array<string, mixed>}> */
    public static array $requests = [];
    /** @var (Closure(string, array<string, mixed>): mixed)|null */
    public static ?Closure $http = null;
    /** @var (Closure(PHPMailer, array<string, mixed>): bool)|null */
    public static ?Closure $coreSender = null;
    /** @var list<array{mailer: PHPMailer, atts: array<string, mixed>}> */
    public static array $coreSent = [];
    /** @var list<array{0: string, 1: mixed}> */
    public static array $actions = [];
    public static bool $multisite = false;
    public static int $blog = 1;
    /** @var list<int> */
    public static array $blogStack = [];
    /** @var list<int> */
    public static array $sites = [1];
    public static bool $canViewSiteHealth = true;

    public static function reset(): void
    {
        self::$hooks = [];
        self::$options = [];
        self::$transients = [];
        self::$requests = [];
        self::$http = null;
        self::$coreSender = null;
        self::$coreSent = [];
        self::$actions = [];
        self::$multisite = false;
        self::$blog = 1;
        self::$blogStack = [];
        self::$sites = [1];
        self::$canViewSiteHealth = true;
    }

    /**
     * @return list<array{0: callable, 1: int}>
     */
    public static function callbacks(string $tag): array
    {
        $byPriority = self::$hooks[$tag] ?? [];
        ksort($byPriority);
        $out = [];
        foreach ($byPriority as $list) {
            foreach ($list as $entry) {
                $out[] = $entry;
            }
        }

        return $out;
    }
}

final class WP_Error
{
    /**
     * @param mixed $data
     */
    public function __construct(private string|int $code = '', private string $message = '', private $data = '')
    {
    }

    public function get_error_code(): string|int
    {
        return $this->code;
    }

    public function get_error_message(): string
    {
        return $this->message;
    }

    /** @return mixed */
    public function get_error_data()
    {
        return $this->data;
    }
}

function add_filter(string $tag, callable $cb, int $priority = 10, int $accepted = 1): bool
{
    WpStubs::$hooks[$tag][$priority][] = [$cb, $accepted];

    return true;
}

function add_action(string $tag, callable $cb, int $priority = 10, int $accepted = 1): bool
{
    return add_filter($tag, $cb, $priority, $accepted);
}

function remove_filter(string $tag, callable $cb, int $priority = 10): bool
{
    foreach (WpStubs::$hooks[$tag][$priority] ?? [] as $i => $entry) {
        if ($entry[0] === $cb) {
            unset(WpStubs::$hooks[$tag][$priority][$i]);
            WpStubs::$hooks[$tag][$priority] = array_values(WpStubs::$hooks[$tag][$priority]);

            return true;
        }
    }

    return false;
}

function remove_action(string $tag, callable $cb, int $priority = 10): bool
{
    return remove_filter($tag, $cb, $priority);
}

/**
 * @param mixed $value
 * @param mixed ...$args
 *
 * @return mixed
 */
function apply_filters(string $tag, $value, ...$args)
{
    foreach (WpStubs::callbacks($tag) as [$cb, $accepted]) {
        $value = $cb(...array_slice([$value, ...$args], 0, $accepted));
    }

    return $value;
}

/**
 * @param mixed ...$args
 */
function do_action(string $tag, ...$args): void
{
    WpStubs::$actions[] = [$tag, $args];
    foreach (WpStubs::callbacks($tag) as [$cb, $accepted]) {
        $cb(...array_slice($args, 0, $accepted));
    }
}

/**
 * @param array<mixed> $args
 */
function do_action_ref_array(string $tag, array $args): void
{
    WpStubs::$actions[] = [$tag, $args];
    foreach (WpStubs::callbacks($tag) as [$cb, $accepted]) {
        $cb(...array_slice($args, 0, $accepted));
    }
}

/**
 * @param mixed $default
 *
 * @return mixed
 */
function get_option(string $name, $default = false)
{
    return array_key_exists($name, WpStubs::$options[WpStubs::$blog] ?? [])
        ? WpStubs::$options[WpStubs::$blog][$name]
        : $default;
}

/**
 * @param mixed $value
 */
function update_option(string $name, $value): bool
{
    WpStubs::$options[WpStubs::$blog][$name] = $value;

    return true;
}

function delete_option(string $name): bool
{
    unset(WpStubs::$options[WpStubs::$blog][$name]);

    return true;
}

/**
 * @return mixed
 */
function get_transient(string $name)
{
    return WpStubs::$transients[WpStubs::$blog][$name]['value'] ?? false;
}

/**
 * @param mixed $value
 */
function set_transient(string $name, $value, int $expiration = 0): bool
{
    WpStubs::$transients[WpStubs::$blog][$name] = ['value' => $value, 'expiration' => $expiration];

    return true;
}

function delete_transient(string $name): bool
{
    unset(WpStubs::$transients[WpStubs::$blog][$name]);

    return true;
}

function is_multisite(): bool
{
    return WpStubs::$multisite;
}

/**
 * @param array<string, mixed> $args
 *
 * @return list<int>
 */
function get_sites(array $args = []): array
{
    return WpStubs::$sites;
}

function switch_to_blog(int $id): bool
{
    WpStubs::$blogStack[] = WpStubs::$blog;
    WpStubs::$blog = $id;

    return true;
}

function restore_current_blog(): bool
{
    WpStubs::$blog = array_pop(WpStubs::$blogStack) ?? 1;

    return true;
}

function wp_salt(string $scheme = 'auth'): string
{
    return 'test-salt-' . $scheme;
}

function get_bloginfo(string $show = ''): string
{
    return $show === 'charset' ? 'UTF-8' : 'Test site';
}

function network_home_url(string $path = ''): string
{
    return 'https://www.example.net' . $path;
}

/**
 * @return mixed
 */
function wp_parse_url(string $url, int $component = -1)
{
    return parse_url($url, $component);
}

function __(string $text, string $domain = 'default'): string
{
    return $text;
}

function esc_html(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function current_user_can(string $capability): bool
{
    return WpStubs::$canViewSiteHealth;
}

/**
 * @param mixed $thing
 */
function is_wp_error($thing): bool
{
    return $thing instanceof WP_Error;
}

/**
 * @param array<string, mixed> $args
 *
 * @return mixed
 */
function wp_remote_post(string $url, array $args = [])
{
    WpStubs::$requests[] = ['url' => $url, 'args' => $args];
    if (WpStubs::$http === null) {
        return new WP_Error('http_request_failed', 'cURL error 7: no stub responder');
    }

    return (WpStubs::$http)($url, $args);
}

/**
 * @param mixed $response
 */
function wp_remote_retrieve_response_code($response): int|string
{
    return is_array($response) ? (int) ($response['response']['code'] ?? 0) : '';
}

/**
 * @param mixed $response
 */
function wp_remote_retrieve_body($response): string
{
    return is_array($response) && is_string($response['body'] ?? null) ? $response['body'] : '';
}

/**
 * Core wp_mail(), reduced: the same filter order and the same events. The
 * "send" is WpStubs::$coreSender, which plays PHPMailer::send().
 *
 * @param string|list<string> $to
 * @param string|list<string> $headers
 * @param string|list<string> $attachments
 */
function wp_mail($to, string $subject, string $message, $headers = '', $attachments = []): bool
{
    $atts = apply_filters('wp_mail', compact('to', 'subject', 'message', 'headers', 'attachments'));
    $pre = apply_filters('pre_wp_mail', null, $atts);
    if ($pre !== null) {
        return (bool) $pre;
    }

    $parsed = \IndianOpinion\SignedMailGateway\MessageBuilder::parseHeaders($atts['headers']);
    $mailer = new PHPMailer(true);
    $from = $parsed['from_email'] !== '' ? $parsed['from_email'] : 'wordpress@example.net';
    $mailer->setFrom(
        (string) apply_filters('wp_mail_from', $from),
        (string) apply_filters('wp_mail_from_name', $parsed['from_name'] !== '' ? $parsed['from_name'] : 'WordPress'),
        false
    );
    $toList = is_array($atts['to']) ? $atts['to'] : explode(',', $atts['to']);
    foreach ($toList as $address) {
        try {
            $mailer->addAddress(trim($address));
        } catch (\PHPMailer\PHPMailer\Exception) {
            continue;
        }
    }
    foreach ($parsed['custom'] as $name => $value) {
        $mailer->addCustomHeader($name, $value);
    }
    $mailer->Subject = $atts['subject'];
    $mailer->Body = $atts['message'];
    do_action_ref_array('phpmailer_init', [&$mailer]);

    $mailData = [
        'to' => $toList,
        'subject' => $atts['subject'],
        'message' => $atts['message'],
        'headers' => $parsed['custom'],
        'attachments' => $atts['attachments'],
    ];
    WpStubs::$coreSent[] = ['mailer' => $mailer, 'atts' => $atts];
    $ok = WpStubs::$coreSender !== null ? (WpStubs::$coreSender)($mailer, $atts) : true;
    if ($ok) {
        do_action('wp_mail_succeeded', $mailData);

        return true;
    }
    do_action('wp_mail_failed', new WP_Error('wp_mail_failed', 'SMTP Error: core stub failure', $mailData));

    return false;
}
