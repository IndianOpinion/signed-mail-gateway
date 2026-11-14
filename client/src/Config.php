<?php

declare(strict_types=1);

namespace IndianOpinion\SignedMailGateway;

/**
 * Client configuration, read fresh for each message.
 *
 * URL and secret come from constants (which win), else from the env file the
 * `SIGNED_MAIL_GATEWAY_ENV_FILE` constant names, then pass through the
 * `signed_mail_gateway_config` filter. Everything else is a per-site option.
 */
final class Config
{
    public const PROVIDER_GATEWAY = 'gateway';
    public const PROVIDER_WORDPRESS = 'wordpress';
    public const MIN_SECRET_BYTES = 32;

    public const OPTION_PROVIDER = 'signed_mail_gateway_provider';
    public const OPTION_FALLBACK = 'signed_mail_gateway_fallback';
    public const OPTION_DOMAINS = 'signed_mail_gateway_domains';
    public const OPTION_DEFAULT_FROM = 'signed_mail_gateway_default_from';
    public const OPTIONS = [
        self::OPTION_PROVIDER,
        self::OPTION_FALLBACK,
        self::OPTION_DOMAINS,
        self::OPTION_DEFAULT_FROM,
    ];

    private const ENV_KEYS = ['SIGNED_MAIL_GATEWAY_URL', 'SIGNED_MAIL_GATEWAY_SECRET'];

    /**
     * @param list<string> $domains     Sender domains the gateway accepts, lower case.
     * @param string|null  $problem     Why the gateway cannot be used, or null when it can.
     * @param list<string> $warnings    Non-fatal configuration notices.
     */
    public function __construct(
        public readonly string $url,
        #[\SensitiveParameter] public readonly string $secret,
        public readonly string $provider = self::PROVIDER_GATEWAY,
        public readonly bool $fallback = true,
        public readonly array $domains = [],
        public readonly string $defaultFrom = '',
        public readonly ?string $problem = null,
        public readonly array $warnings = [],
    ) {
    }

    /**
     * What `var_dump()` and `print_r()` show: everything but the secret.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        $info = get_object_vars($this);
        $info['secret'] = $this->secret === '' ? '' : '[redacted]';

        return $info;
    }

    public function isConfigured(): bool
    {
        return $this->problem === null;
    }

    /** The full URL of one route, for example `/v1/send`. */
    public function endpoint(string $route): string
    {
        return rtrim($this->url, '/') . $route;
    }

    public function gatewayDomain(string $domain): bool
    {
        return in_array(strtolower($domain), $this->domains, true);
    }

    public static function load(): self
    {
        $warnings = [];
        $url = self::constant('SIGNED_MAIL_GATEWAY_URL');
        // The secret is used byte for byte, as the Worker uses it: no trimming.
        $secret = self::constant('SIGNED_MAIL_GATEWAY_SECRET', false);

        $file = self::constant('SIGNED_MAIL_GATEWAY_ENV_FILE');
        if ($file !== '' && ($url === '' || $secret === '')) {
            $values = self::readEnvFile($file, $warnings);
            $url = $url !== '' ? $url : ($values['SIGNED_MAIL_GATEWAY_URL'] ?? '');
            $secret = $secret !== '' ? $secret : ($values['SIGNED_MAIL_GATEWAY_SECRET'] ?? '');
        }

        $filtered = apply_filters('signed_mail_gateway_config', ['url' => $url, 'secret' => $secret]);
        if (is_array($filtered)) {
            $url = is_string($filtered['url'] ?? null) ? $filtered['url'] : $url;
            $secret = is_string($filtered['secret'] ?? null) ? $filtered['secret'] : $secret;
        }
        $url = trim($url);

        $provider = get_option(self::OPTION_PROVIDER, self::PROVIDER_GATEWAY);
        $provider = $provider === self::PROVIDER_WORDPRESS ? self::PROVIDER_WORDPRESS : self::PROVIDER_GATEWAY;

        $fallback = get_option(self::OPTION_FALLBACK, '1');
        $fallback = !in_array(
            is_string($fallback) ? strtolower(trim($fallback)) : $fallback,
            ['0', 0, false, 'no', 'off', 'false'],
            true
        );

        $domains = get_option(self::OPTION_DOMAINS, '');
        $domains = is_string($domains) ? self::domainList($domains) : [];

        $defaultFrom = get_option(self::OPTION_DEFAULT_FROM, '');
        $defaultFrom = is_string($defaultFrom) ? trim($defaultFrom) : '';
        if ($defaultFrom !== '' && Address::parse($defaultFrom) === null) {
            $warnings[] = 'default_from_invalid';
            $defaultFrom = '';
        }

        return new self(
            $url,
            $secret,
            $provider,
            $fallback,
            $domains,
            $defaultFrom,
            self::problem($url, $secret),
            $warnings,
        );
    }

    /**
     * Why a URL and secret cannot be used, or null when they can.
     */
    public static function problem(string $url, string $secret): ?string
    {
        if ($url === '') {
            return 'missing_url';
        }
        $parts = parse_url($url);
        if (
            !is_array($parts)
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || ($parts['host'] ?? '') === ''
            || isset($parts['user'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            return 'insecure_url';
        }
        if ($secret === '') {
            return 'missing_secret';
        }
        if (strlen($secret) < self::MIN_SECRET_BYTES) {
            return 'short_secret';
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function domainList(string $raw): array
    {
        $out = [];
        foreach (explode(',', $raw) as $part) {
            $part = strtolower(trim($part));
            if ($part !== '' && !in_array($part, $out, true)) {
                $out[] = $part;
            }
        }

        return $out;
    }

    private static function constant(string $name, bool $trim = true): string
    {
        if (!defined($name)) {
            return '';
        }
        $value = constant($name);
        if (!is_string($value)) {
            return '';
        }

        return $trim ? trim($value) : $value;
    }

    /**
     * Reads `KEY=value` lines. Blank lines and `#` comments are skipped,
     * surrounding quotes removed. Only the two known keys are kept.
     *
     * @param list<string> $warnings
     *
     * @return array<string, string>
     */
    private static function readEnvFile(string $path, array &$warnings): array
    {
        if (defined('ABSPATH')) {
            $root = realpath((string) constant('ABSPATH'));
            $real = realpath($path);
            if ($root !== false && $real !== false && str_starts_with($real, rtrim($root, '/') . '/')) {
                $warnings[] = 'env_file_in_web_root';
            }
        }
        if (!is_file($path) || !is_readable($path)) {
            $warnings[] = 'env_file_unreadable';

            return [];
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            $warnings[] = 'env_file_unreadable';

            return [];
        }
        $values = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim(preg_replace('/^export\s+/', '', trim($key)) ?? '');
            $value = trim($value);
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && substr($value, -1) === $value[0]) {
                $value = substr($value, 1, -1);
            }
            if (in_array($key, self::ENV_KEYS, true)) {
                $values[$key] = $value;
            }
        }

        return $values;
    }
}
