<?php

declare(strict_types=1);

namespace IndianOpinion\SignedMailGateway;

/**
 * Request signing. Pure PHP, no WordPress.
 *
 * The signature is the lower-case hex HMAC-SHA256, under the secret's bytes,
 * of `<timestamp>.<nonce>.<body>`. The body is the exact string sent.
 *
 * The secret parameters are marked sensitive, so PHP 8.2 and later replace
 * them in stack traces; on 8.1 the attribute is ignored and traces show at
 * most the first 15 bytes, which is why nothing here throws with the secret
 * in scope.
 */
final class Signer
{
    public const HEADER_TIMESTAMP = 'X-Gateway-Timestamp';
    public const HEADER_NONCE = 'X-Gateway-Nonce';
    public const HEADER_SIGNATURE = 'X-Gateway-Signature';

    public static function sign(
        #[\SensitiveParameter] string $secret,
        string $timestamp,
        string $nonce,
        string $body,
    ): string {
        return hash_hmac('sha256', $timestamp . '.' . $nonce . '.' . $body, $secret);
    }

    /** A fresh nonce: 16 random bytes, lower-case hex. */
    public static function nonce(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * The three signature headers for one request.
     *
     * @return array<string, string>
     */
    public static function headers(
        #[\SensitiveParameter] string $secret,
        string $body,
        int $now,
        ?string $nonce = null,
    ): array {
        $timestamp = sprintf('%010d', $now);
        $nonce ??= self::nonce();

        return [
            self::HEADER_TIMESTAMP => $timestamp,
            self::HEADER_NONCE => $nonce,
            self::HEADER_SIGNATURE => self::sign($secret, $timestamp, $nonce, $body),
        ];
    }
}
