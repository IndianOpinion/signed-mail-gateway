<?php

declare(strict_types=1);

namespace IndianOpinion\SignedMailGateway;

use PHPMailer\PHPMailer\PHPMailer;

/**
 * The `pre_wp_mail` handler: sends each `wp_mail()` call through the gateway
 * and decides, from one classifier, whether the core path may try once.
 */
final class Mailer
{
    /** True while the core path runs as the fallback (lesson 21). */
    private bool $inFallback = false;
    /** True while the private PHPMailer is being built. */
    private bool $building = false;
    /** The profile named on the current `wp_mail()` call, if any. */
    private ?string $profileName = null;
    private ?Result $last = null;

    /** @var \Closure(Config): GatewayClient */
    private \Closure $clientFactory;

    /**
     * @param (callable(Config): GatewayClient)|null $clientFactory For tests: how to make a gateway client.
     */
    public function __construct(private readonly Logger $logger, ?callable $clientFactory = null)
    {
        $this->clientFactory = $clientFactory !== null
            ? \Closure::fromCallable($clientFactory)
            : fn (Config $config): GatewayClient => new GatewayClient($config, $this->logger);
    }

    /**
     * `wp_mail` filter, last priority: removes the profile header before
     * either path sees the message, and remembers the profile name.
     *
     * @param mixed $atts
     *
     * @return mixed
     */
    public function filterAtts($atts)
    {
        if ($this->inFallback) {
            return $atts;
        }
        $this->profileName = null;
        if (!is_array($atts)) {
            return $atts;
        }
        [$headers, $profile] = MessageBuilder::extractProfile($atts['headers'] ?? []);
        if ($profile !== null) {
            $atts['headers'] = $headers;
            $this->profileName = $profile;
        }

        return $atts;
    }

    /**
     * `phpmailer_init`, last priority: on the core path, a known profile sets the From.
     */
    public function applyProfileToCore(mixed $mailer): void
    {
        if ($this->building || !$mailer instanceof PHPMailer) {
            return;
        }
        $profile = $this->resolveProfile(false);
        if ($profile === null) {
            return;
        }
        try {
            $mailer->setFrom($profile['email'], $profile['name'], false);
        } catch (\Throwable) {
            $this->logger->notice('profile_from_refused', ['profile' => $this->profileName]);
        }
    }

    /**
     * `pre_wp_mail` filter.
     *
     * @param mixed $return Null, or what an earlier filter decided.
     * @param mixed $atts   The `wp_mail()` attributes, as core passes them.
     *
     * @return mixed Null to let core send, else true or false.
     */
    public function preWpMail($return, $atts)
    {
        if ($return !== null || $this->inFallback) {
            return $return;
        }
        $atts = is_array($atts) ? $atts : [];

        $config = Config::load();
        foreach ($config->warnings as $warning) {
            $this->logger->notice($warning);
        }
        if ($config->provider === Config::PROVIDER_WORDPRESS) {
            return null;
        }
        if (apply_filters('signed_mail_gateway_bypass', false, $atts) === true) {
            return null;
        }

        $started = microtime(true);
        $profile = $this->resolveProfile(true);
        $built = null;
        if (!$config->isConfigured()) {
            $this->logger->notice('not_configured', ['detail' => $config->problem]);
            $result = new Result(Outcome::UNAVAILABLE, 'not_configured');
        } else {
            $this->building = true;
            try {
                $built = (new MessageBuilder($config))->build($atts, $profile);
            } finally {
                $this->building = false;
            }
            if ($built->hasAttachments) {
                $result = new Result(Outcome::UNAVAILABLE, 'attachments_unsupported');
            } elseif ($built->payload['to'] === []) {
                $result = new Result(Outcome::REJECTED, 'no_recipient');
            } else {
                $payload = apply_filters('signed_mail_gateway_payload', $built->payload, $atts);
                $payload = is_array($payload) ? $payload : $built->payload;
                /** @var array<string, mixed> $payload */
                $result = ($this->clientFactory)($config)->send($payload);
            }
        }

        $this->logAttempt(1, $result, $built, $started);

        if ($result->isSent()) {
            return $this->finish($result, $atts, $built, true);
        }

        $fallbackClasses = apply_filters('signed_mail_gateway_fallback_classes', Outcome::FALLBACK_DEFAULT);
        $fallbackClasses = is_array($fallbackClasses) ? $fallbackClasses : Outcome::FALLBACK_DEFAULT;
        if ($config->fallback && in_array($result->class, $fallbackClasses, true)) {
            return $this->fallback($result, $atts, $built);
        }

        return $this->finish($result, $atts, $built, false);
    }

    /**
     * The result of the last decision in this request.
     *
     * @return array<string, mixed>
     */
    public function lastResult(): array
    {
        return $this->last !== null ? $this->last->toArray() : [];
    }

    /**
     * Batch API: one signed `/v1/batch` call. Never falls back.
     *
     * @param array<array-key, mixed> $messages
     *
     * @return list<array<string, mixed>>
     */
    public function sendBatch(array $messages): array
    {
        $config = Config::load();
        $started = microtime(true);
        $results = ($this->clientFactory)($config)->sendBatch($messages);
        $out = [];
        foreach ($results as $i => $result) {
            $message = $messages[array_keys($messages)[$i] ?? $i] ?? null;
            $this->logBatchAttempt($result, is_array($message) ? $message : [], $started);
            $out[] = $result->toArray();
        }

        return $out;
    }

    /**
     * Sends through the core path once, with the guard set. The gateway's
     * verdict stands if this fails too (lesson 10).
     *
     * @param array<string, mixed> $atts
     */
    private function fallback(Result $gateway, array $atts, ?BuiltMessage $built): bool
    {
        $started = microtime(true);
        $coreError = '';
        $capture = static function ($error) use (&$coreError): void {
            if ($error instanceof \WP_Error) {
                $coreError = (string) $error->get_error_code();
            }
        };
        add_action('wp_mail_failed', $capture);
        $this->inFallback = true;
        try {
            $ok = wp_mail(
                $atts['to'] ?? '',
                is_string($atts['subject'] ?? null) ? $atts['subject'] : '',
                is_string($atts['message'] ?? null) ? $atts['message'] : '',
                $atts['headers'] ?? '',
                $atts['attachments'] ?? []
            ) === true;
        } catch (\Throwable $e) {
            $ok = false;
            $coreError = 'exception_' . (new \ReflectionClass($e))->getShortName();
        } finally {
            $this->inFallback = false;
            remove_action('wp_mail_failed', $capture);
        }

        $core = $ok
            ? new Result(Outcome::SENT, 'sent', path: Outcome::PATH_WORDPRESS, attempts: 2)
            : new Result($gateway->class, $coreError !== '' ? $coreError : 'core_failed', path: Outcome::PATH_WORDPRESS, attempts: 2);
        $this->logAttempt(2, $core, $built, $started, $ok ? Outcome::SENT : 'failed');

        if ($ok) {
            // Core already fired wp_mail_succeeded for its own send.
            $this->last = new Result(Outcome::SENT, $gateway->code, null, '', Outcome::PATH_WORDPRESS, 2);
            do_action('signed_mail_gateway_result', $this->last->toArray(), $atts);

            return true;
        }

        // Core fired wp_mail_failed for its own attempt; the gateway's verdict is the reason.
        $this->last = $gateway->withPath(Outcome::PATH_WORDPRESS, 2);
        do_action('signed_mail_gateway_result', $this->last->toArray(), $atts);

        return false;
    }

    /**
     * Ends a message decided by the gateway alone, firing core's mail events
     * as core would have (lesson 20).
     *
     * @param array<string, mixed> $atts
     */
    private function finish(Result $result, array $atts, ?BuiltMessage $built, bool $sent): bool
    {
        $this->last = $result->withPath(Outcome::PATH_GATEWAY, 1);
        $mailData = $built !== null ? $built->mailData : self::fallbackMailData($atts);
        if ($sent) {
            do_action('wp_mail_succeeded', $mailData);
        } else {
            $mailData['phpmailer_exception_code'] = 0;
            do_action('wp_mail_failed', new \WP_Error(
                'wp_mail_failed',
                sprintf('signed-mail-gateway: %s (%s)', $result->code, $result->class),
                $mailData
            ));
        }
        do_action('signed_mail_gateway_result', $this->last->toArray(), $atts);

        return $sent;
    }

    /**
     * The profile named on this call, if the operator defined it.
     *
     * @return array{email: string, name: string}|null
     */
    private function resolveProfile(bool $logUnknown): ?array
    {
        if ($this->profileName === null || $this->profileName === '') {
            return null;
        }
        $profiles = apply_filters('signed_mail_gateway_profiles', []);
        $profile = is_array($profiles) ? ($profiles[$this->profileName] ?? null) : null;
        if (!is_array($profile) || !is_string($profile['email'] ?? null) || !Address::isValid($profile['email'])) {
            if ($logUnknown) {
                $this->logger->notice('unknown_profile', ['profile' => self::safeName($this->profileName)]);
            }

            return null;
        }

        return [
            'email' => $profile['email'],
            'name' => is_string($profile['name'] ?? null) ? $profile['name'] : '',
        ];
    }

    private function logAttempt(int $attempt, Result $result, ?BuiltMessage $built, float $started, ?string $class = null): void
    {
        $this->logger->attempt([
            'attempt' => $attempt,
            'path' => $attempt === 1 ? Outcome::PATH_GATEWAY : Outcome::PATH_WORDPRESS,
            'class' => $class ?? $result->class,
            'code' => $result->code,
            'status' => $result->status,
            'provider_id' => $result->id,
            'rcpt_hash' => $built !== null ? Logger::recipientHash($built->firstRecipient) : '',
            'rcpt' => $built !== null ? $built->recipients : 0,
            'profile' => $this->profileName !== null ? self::safeName($this->profileName) : null,
            'dropped' => count($result->droppedHeaders) + ($built !== null ? $built->droppedLocally : 0),
            'ms' => (int) round((microtime(true) - $started) * 1000),
        ]);
    }

    /**
     * @param array<mixed> $message
     */
    private function logBatchAttempt(Result $result, array $message, float $started): void
    {
        $to = $message['to'] ?? [];
        $first = is_array($to) ? ($to[0] ?? '') : $to;
        if (is_array($first)) {
            $first = $first['email'] ?? '';
        }
        $parsed = is_string($first) ? Address::parse($first) : null;
        $this->logger->attempt([
            'attempt' => 1,
            'path' => Outcome::PATH_GATEWAY,
            'route' => 'batch',
            'class' => $result->class,
            'code' => $result->code,
            'status' => $result->status,
            'provider_id' => $result->id,
            'rcpt_hash' => $parsed !== null ? Logger::recipientHash($parsed['email']) : '',
            'dropped' => count($result->droppedHeaders),
            'ms' => (int) round((microtime(true) - $started) * 1000),
        ]);
    }

    /** Profile names are operator words, but a caller could pass anything: keep it short and plain. */
    private static function safeName(string $name): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_.-]/', '?', $name) ?? '';

        return substr($clean, 0, 40);
    }

    /**
     * @param array<string, mixed> $atts
     *
     * @return array<string, mixed>
     */
    private static function fallbackMailData(array $atts): array
    {
        $to = $atts['to'] ?? [];

        return [
            'to' => is_array($to) ? $to : explode(',', is_string($to) ? $to : ''),
            'subject' => $atts['subject'] ?? '',
            'message' => $atts['message'] ?? '',
            'headers' => MessageBuilder::parseHeaders($atts['headers'] ?? [])['custom'],
            'attachments' => $atts['attachments'] ?? [],
        ];
    }
}
