<?php

declare(strict_types=1);

namespace IndianOpinion\SignedMailGateway;

/**
 * Log lines for operators. Codes, counts, ids and keyed hashes only: never
 * the secret, the signature, the body, the subject or an address.
 */
final class Logger
{
    public const PREFIX = 'signed-mail-gateway: ';

    /** @var \Closure(string): void */
    private \Closure $sink;

    /**
     * @param (callable(string): void)|null $sink Where lines go. Defaults to `error_log()`.
     */
    public function __construct(?callable $sink = null)
    {
        $this->sink = $sink !== null
            ? \Closure::fromCallable($sink)
            : static function (string $line): void {
                error_log($line);
            };
    }

    /**
     * One line per attempt, also given to the `signed_mail_gateway_attempt` action.
     *
     * @param array<string, scalar|null> $fields
     */
    public function attempt(array $fields): void
    {
        $fields = ['event' => 'attempt'] + $fields;
        $this->write($fields);
        do_action('signed_mail_gateway_attempt', $fields);
    }

    /**
     * A notice that is not an attempt: configuration problems, an unknown
     * profile, a clock difference.
     *
     * @param array<string, scalar|null> $fields
     */
    public function notice(string $code, array $fields = []): void
    {
        $this->write(['event' => 'notice', 'code' => $code] + $fields);
    }

    /**
     * Keyed hash of an address: HMAC-SHA256 under a WordPress salt, first 16
     * hex characters. Lets an operator match lines for one reader without
     * the address being in the log.
     */
    public static function recipientHash(string $address): string
    {
        if ($address === '') {
            return '';
        }
        $key = function_exists('wp_salt') ? wp_salt('auth') : 'signed-mail-gateway';

        return substr(hash_hmac('sha256', strtolower(trim($address)), $key), 0, 16);
    }

    /**
     * @param array<string, scalar|null> $fields
     */
    private function write(array $fields): void
    {
        $json = json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        ($this->sink)(self::PREFIX . ($json === false ? '{"event":"log_encode_failed"}' : $json));
    }
}
