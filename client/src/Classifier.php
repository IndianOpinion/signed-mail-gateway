<?php

declare(strict_types=1);

namespace IndianOpinion\SignedMailGateway;

/**
 * One classifier for every path (PRD case 77, lesson 12).
 *
 * The single-message path, the batch API, the check command and Site Health
 * all turn a raw attempt into a class here, so the same reply never means
 * "fall back" in one place and "give up" in another.
 */
final class Classifier
{
    /** Gateway and client codes whose class is fixed. */
    private const CODES = [
        // Client side.
        'no_recipient' => Outcome::REJECTED,
        'not_configured' => Outcome::UNAVAILABLE,
        'attachments_unsupported' => Outcome::UNAVAILABLE,
        'encode_failed' => Outcome::UNAVAILABLE,
        'invalid_message' => Outcome::REJECTED,
        // Gateway: the message is wrong.
        'bad_recipient' => Outcome::REJECTED,
        'too_many_recipients' => Outcome::REJECTED,
        'bad_subject' => Outcome::REJECTED,
        'no_body' => Outcome::REJECTED,
        'bad_from' => Outcome::REJECTED,
        'bad_reply_to' => Outcome::REJECTED,
        'bad_json' => Outcome::REJECTED,
        // Gateway: certainly not sent, try later.
        'stale_timestamp' => Outcome::RETRY,
        'replay' => Outcome::RETRY,
        // Gateway: certainly not sent, cannot take it.
        'auth_failed' => Outcome::UNAVAILABLE,
        'misconfigured' => Outcome::UNAVAILABLE,
        'nonce_store_unavailable' => Outcome::UNAVAILABLE,
        'domain_unknown' => Outcome::UNAVAILABLE,
        'domain_disabled' => Outcome::UNAVAILABLE,
        'batch_too_large' => Outcome::UNAVAILABLE,
        'body_too_large' => Outcome::UNAVAILABLE,
        'sender_not_allowed' => Outcome::UNAVAILABLE,
    ];

    /** Provider codes whose class is fixed. Other 4xx codes are `rejected`, other 5xx `unknown`. */
    private const PROVIDER_CODES = [
        'E_RECIPIENT_SUPPRESSED' => Outcome::SUPPRESSED,
        'E_DAILY_LIMIT_EXCEEDED' => Outcome::QUOTA,
        'E_RATE_LIMIT_EXCEEDED' => Outcome::RETRY,
        'E_SENDER_NOT_VERIFIED' => Outcome::UNAVAILABLE,
        'E_SENDER_DOMAIN_NOT_AVAILABLE' => Outcome::UNAVAILABLE,
        'E_SENDER_NOT_ALLOWED' => Outcome::UNAVAILABLE,
        'E_RECIPIENT_NOT_ALLOWED' => Outcome::UNAVAILABLE,
        'E_ACCOUNT_SUSPENDED' => Outcome::UNAVAILABLE,
        'E_INTERNAL_SERVER_ERROR' => Outcome::UNAVAILABLE,
    ];

    /** The class of a code the client itself produced, or of a gateway `error` code. */
    public static function forCode(string $code): string
    {
        return self::CODES[$code] ?? Outcome::UNKNOWN;
    }

    /**
     * Classifies a transport failure: the HTTP call itself failed.
     *
     * Only a refused connection or a failed name lookup proves the request
     * never left. Anything else (a timeout after connecting, a reset, a TLS
     * error mid-stream) may have delivered it, so it is `unknown`.
     *
     * @return array{0: string, 1: string} Class and code.
     */
    public static function forTransportError(string $message): array
    {
        if (preg_match('/cURL error (\d+)/i', $message, $m) === 1) {
            $errno = (int) $m[1];
            if ($errno === 6) {
                return [Outcome::UNAVAILABLE, 'dns_failed'];
            }
            if ($errno === 7) {
                return [Outcome::UNAVAILABLE, 'connect_failed'];
            }
            if ($errno === 28) {
                return [Outcome::UNKNOWN, 'timeout'];
            }

            return [Outcome::UNKNOWN, 'transport_error_' . $errno];
        }
        if (preg_match('/timed? ?out/i', $message) === 1) {
            return [Outcome::UNKNOWN, 'timeout'];
        }
        if (preg_match('/could not resolve|name or service not known|getaddrinfo|no such host/i', $message) === 1) {
            return [Outcome::UNAVAILABLE, 'dns_failed'];
        }
        if (preg_match('/connection refused/i', $message) === 1) {
            return [Outcome::UNAVAILABLE, 'connect_failed'];
        }

        return [Outcome::UNKNOWN, 'transport_error'];
    }

    /**
     * Classifies an HTTP reply.
     *
     * @param array<mixed>|null $json The decoded reply object, or null when it was not a JSON object.
     *
     * @return array{0: string, 1: string} Class and code.
     */
    public static function forReply(int $status, ?array $json): array
    {
        if ($json === null) {
            // No JSON: not the gateway's own answer. A 4xx (an unknown route,
            // a proxy or firewall refusal) means the message was not taken.
            // Anything else may have been.
            if ($status >= 400 && $status < 500) {
                return [Outcome::UNAVAILABLE, 'http_' . $status];
            }

            return [Outcome::UNKNOWN, 'bad_reply'];
        }

        if ($status === 200 && ($json['ok'] ?? null) === true) {
            return [Outcome::SENT, 'sent'];
        }

        $error = is_string($json['error'] ?? null) ? $json['error'] : '';
        $providerCode = is_string($json['provider_code'] ?? null) ? $json['provider_code'] : '';

        if ($error === 'provider_error' || ($providerCode !== '' && !isset(self::CODES[$error]))) {
            if (isset(self::PROVIDER_CODES[$providerCode])) {
                return [self::PROVIDER_CODES[$providerCode], $providerCode];
            }
            $code = $providerCode !== '' ? $providerCode : 'provider_error';
            if ($status >= 400 && $status < 500) {
                return [Outcome::REJECTED, $code];
            }

            return [Outcome::UNKNOWN, $code];
        }

        if (isset(self::CODES[$error])) {
            return [self::CODES[$error], $error];
        }

        $code = $error !== '' ? $error : 'http_' . $status;
        if ($status >= 400 && $status < 500) {
            return [Outcome::REJECTED, $code];
        }

        return [Outcome::UNKNOWN, $code];
    }
}
