<?php

declare(strict_types=1);

namespace IndianOpinion\SignedMailGateway;

/**
 * Talks to the gateway over the WordPress HTTP API.
 *
 * Every request is encoded once, signed over exactly those bytes and sent as
 * exactly those bytes (lesson 1). Redirects are never followed (lesson 24).
 * Each call makes one request and never retries: a retry needs a new nonce,
 * and the caller decides whether one is safe.
 */
final class GatewayClient
{
    public const VERSION = '0.1.0';
    public const ROUTE_SEND = '/v1/send';
    public const ROUTE_BATCH = '/v1/batch';
    public const ROUTE_CHECK = '/v1/check';
    public const TIMEOUT_SEND = 10;
    public const TIMEOUT_BATCH_BASE = 30;
    public const TIMEOUT_BATCH_PER_MESSAGE = 5;

    /** @var \Closure(): int */
    private \Closure $clock;

    /**
     * @param (callable(): int)|null $clock Unix time source. Defaults to `time()`.
     */
    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
        ?callable $clock = null,
    ) {
        $this->clock = $clock !== null ? \Closure::fromCallable($clock) : static fn (): int => time();
    }

    /**
     * Sends one message in the `/v1/send` shape.
     *
     * @param array<string, mixed> $message
     */
    public function send(array $message): Result
    {
        $local = $this->localProblem($message);
        if ($local !== null) {
            return $local;
        }
        try {
            $body = self::encode($message);
        } catch (\JsonException) {
            return new Result(Outcome::UNAVAILABLE, 'encode_failed');
        }
        $reply = $this->post(self::ROUTE_SEND, $body, $this->timeout(self::ROUTE_SEND, self::TIMEOUT_SEND));

        return $this->resultFrom($reply);
    }

    /**
     * Sends prepared messages in one signed `/v1/batch` call.
     *
     * Returns one result per input, in input order. Never falls back: bulk
     * callers decide per message what to do next.
     *
     * @param array<array-key, mixed> $messages
     *
     * @return list<Result>
     */
    public function sendBatch(array $messages): array
    {
        $messages = array_values($messages);
        /** @var array<int, Result> $results */
        $results = [];
        /** @var list<int> $indexes */
        $indexes = [];
        /** @var list<array<string, mixed>> $payloads */
        $payloads = [];

        foreach ($messages as $i => $message) {
            if (!is_array($message)) {
                $results[$i] = new Result(Outcome::REJECTED, 'invalid_message');
                continue;
            }
            /** @var array<string, mixed> $message */
            $local = $this->localProblem($message, false);
            if ($local !== null) {
                $results[$i] = $local;
                continue;
            }
            $indexes[] = $i;
            $payloads[] = $message;
        }

        if ($payloads !== []) {
            foreach ($this->sendBatchPayloads($payloads) as $k => $result) {
                $results[$indexes[$k]] = $result;
            }
        }
        ksort($results);

        return array_values($results);
    }

    /**
     * Runs `/v1/check`: every gateway check including the nonce claim, no mail.
     */
    public function check(): Result
    {
        if (!$this->config->isConfigured()) {
            return new Result(Outcome::UNAVAILABLE, 'not_configured');
        }
        $reply = $this->post(self::ROUTE_CHECK, '{}', $this->timeout(self::ROUTE_CHECK, self::TIMEOUT_SEND));
        $result = $this->resultFrom($reply);
        if ($result->isSent()) {
            return new Result(
                Outcome::SENT,
                'ok',
                $result->status,
                reply: $reply->json ?? [],
                serverTime: is_int($reply->json['server_time'] ?? null) ? $reply->json['server_time'] : null,
            );
        }

        return $result;
    }

    /**
     * JSON as the gateway expects it: non-ASCII escaped as `\uXXXX`, slashes
     * not escaped. The returned string is what is signed and what is sent.
     *
     * @throws \JsonException When the message cannot be encoded (for example invalid UTF-8).
     */
    public static function encode(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<array<string, mixed>> $payloads
     *
     * @return list<Result>
     */
    private function sendBatchPayloads(array $payloads): array
    {
        $n = count($payloads);
        if (!$this->config->isConfigured()) {
            return array_fill(0, $n, new Result(Outcome::UNAVAILABLE, 'not_configured'));
        }
        try {
            $body = self::encode(['messages' => $payloads]);
        } catch (\JsonException) {
            return array_fill(0, $n, new Result(Outcome::UNAVAILABLE, 'encode_failed'));
        }
        $timeout = $this->timeout(
            self::ROUTE_BATCH,
            self::TIMEOUT_BATCH_BASE + self::TIMEOUT_BATCH_PER_MESSAGE * $n
        );
        $reply = $this->post(self::ROUTE_BATCH, $body, $timeout);

        // An older gateway without the batch route: one /v1/send per message.
        if ($reply->status === 404 && $reply->json === null) {
            return array_map(fn (array $payload): Result => $this->send($payload), $payloads);
        }

        // The whole call failed: every message gets that one result.
        if ($reply->status !== 200 || $reply->json === null || ($reply->json['batch'] ?? null) !== true) {
            $whole = $this->resultFrom($reply);
            if ($whole->isSent()) {
                // 200 and ok, but not a batch reply: nobody knows what was sent.
                $whole = new Result(Outcome::UNKNOWN, 'bad_batch_reply', $reply->status);
            }

            return array_fill(0, $n, $whole);
        }

        $entries = $reply->json['results'] ?? null;
        if (!is_array($entries) || !array_is_list($entries) || count($entries) !== $n) {
            // Some may have been sent: none can be retried safely.
            return array_fill(0, $n, new Result(Outcome::UNKNOWN, 'bad_batch_reply', 200));
        }

        $out = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || !is_int($entry['status'] ?? null)) {
                $out[] = new Result(Outcome::UNKNOWN, 'bad_batch_reply', 200);
                continue;
            }
            $out[] = $this->resultFrom(new Reply($entry['status'], $entry));
        }

        return $out;
    }

    /**
     * Checks a message can be sent at all before anything is signed.
     *
     * @param array<string, mixed> $message
     */
    private function localProblem(array $message, bool $checkConfig = true): ?Result
    {
        if ($checkConfig && !$this->config->isConfigured()) {
            return new Result(Outcome::UNAVAILABLE, 'not_configured');
        }
        $attachments = $message['attachments'] ?? null;
        if ($attachments !== null && $attachments !== [] && $attachments !== '') {
            return new Result(Outcome::UNAVAILABLE, 'attachments_unsupported');
        }
        $to = $message['to'] ?? null;
        if ($to === null || $to === '' || $to === []) {
            return new Result(Outcome::REJECTED, 'no_recipient');
        }

        return null;
    }

    private function timeout(string $route, int $default): int
    {
        $value = apply_filters('signed_mail_gateway_timeout', $default, $route);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }

    private function post(string $route, string $body, int $timeout): Reply
    {
        $now = ($this->clock)();
        $headers = Signer::headers($this->config->secret, $body, $now);
        $headers['Content-Type'] = 'application/json';

        $response = wp_remote_post($this->config->endpoint($route), [
            'method' => 'POST',
            'timeout' => $timeout,
            'redirection' => 0,
            'user-agent' => 'signed-mail-gateway/' . self::VERSION . ' (WordPress)',
            'headers' => $headers,
            'body' => $body,
            'data_format' => 'body',
        ]);

        if (is_wp_error($response)) {
            return new Reply(null, null, $response->get_error_message());
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        $json = is_array($decoded) && ($decoded === [] || !array_is_list($decoded)) ? $decoded : null;
        $reply = new Reply($status, $json);

        if (($json['error'] ?? null) === 'stale_timestamp' && is_int($json['server_time'] ?? null)) {
            $this->reportClock($now, $json['server_time']);
        }

        return $reply;
    }

    /** Lesson 27: say how far off the origin clock is, not just "auth failed". */
    private function reportClock(int $local, int $server): void
    {
        $diff = $local - $server;
        $this->logger->notice('clock_difference', [
            'local_time' => $local,
            'server_time' => $server,
            'diff_s' => $diff,
            'detail' => sprintf('origin clock is %d s %s', abs($diff), $diff >= 0 ? 'fast' : 'slow'),
        ]);
    }

    private function resultFrom(Reply $reply): Result
    {
        if ($reply->failedInTransport()) {
            [$class, $code] = Classifier::forTransportError($reply->transportError);

            return new Result($class, $code);
        }
        $status = (int) $reply->status;
        [$class, $code] = Classifier::forReply($status, $reply->json);
        $json = $reply->json ?? [];
        $dropped = [];
        if (is_array($json['dropped_headers'] ?? null)) {
            foreach ($json['dropped_headers'] as $name) {
                if (is_string($name)) {
                    $dropped[] = $name;
                }
            }
        }

        return new Result(
            $class,
            $code,
            $status,
            is_string($json['id'] ?? null) ? $json['id'] : '',
            Outcome::PATH_GATEWAY,
            1,
            is_string($json['provider_code'] ?? null) ? $json['provider_code'] : '',
            is_string($json['domain'] ?? null) ? $json['domain'] : '',
            $dropped,
            is_int($json['server_time'] ?? null) ? $json['server_time'] : null,
        );
    }
}
