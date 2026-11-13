<?php

declare(strict_types=1);

namespace IndianOpinion\SignedMailGateway\Tests;

use IndianOpinion\SignedMailGateway\Classifier;
use IndianOpinion\SignedMailGateway\Outcome;
use PHPUnit\Framework\Attributes\DataProvider;

/** C17 and C21: every code and transport error in case 77 maps to its class. */
final class ClassifierTest extends TestCase
{
    /**
     * @return iterable<string, array{0: int, 1: array<string, mixed>|null, 2: string}>
     */
    public static function replies(): iterable
    {
        yield 'sent' => [200, ['ok' => true, 'id' => 'x'], Outcome::SENT];
        $provider = static fn (string $code): array => ['ok' => false, 'error' => 'provider_error', 'provider_code' => $code];
        yield 'suppressed' => [422, $provider('E_RECIPIENT_SUPPRESSED'), Outcome::SUPPRESSED];
        foreach (['bad_recipient', 'too_many_recipients', 'bad_subject', 'no_body', 'bad_from', 'bad_reply_to', 'bad_json'] as $code) {
            yield $code => [400, ['ok' => false, 'error' => $code], Outcome::REJECTED];
        }
        yield 'other 4xx code' => [400, ['ok' => false, 'error' => 'something_new'], Outcome::REJECTED];
        yield 'provider validation' => [400, $provider('E_VALIDATION_ERROR'), Outcome::REJECTED];
        yield 'rate limit' => [429, $provider('E_RATE_LIMIT_EXCEEDED'), Outcome::RETRY];
        yield 'stale_timestamp' => [401, ['ok' => false, 'error' => 'stale_timestamp', 'server_time' => 1], Outcome::RETRY];
        yield 'replay' => [409, ['ok' => false, 'error' => 'replay'], Outcome::RETRY];
        yield 'quota' => [429, $provider('E_DAILY_LIMIT_EXCEEDED'), Outcome::QUOTA];
        foreach (
            ['auth_failed' => 401, 'misconfigured' => 503, 'nonce_store_unavailable' => 503, 'domain_unknown' => 403,
                'domain_disabled' => 403, 'batch_too_large' => 400, 'body_too_large' => 413, 'attachments_unsupported' => 400,
                'sender_not_allowed' => 403] as $code => $status
        ) {
            yield $code => [$status, ['ok' => false, 'error' => $code], Outcome::UNAVAILABLE];
        }
        foreach (
            ['E_SENDER_NOT_VERIFIED' => 424, 'E_SENDER_DOMAIN_NOT_AVAILABLE' => 424, 'E_RECIPIENT_NOT_ALLOWED' => 403,
                'E_INTERNAL_SERVER_ERROR' => 503] as $code => $status
        ) {
            yield $code => [$status, ['ok' => false, 'error' => 'provider_error', 'provider_code' => $code], Outcome::UNAVAILABLE];
        }
        yield 'internal_error 500' => [500, ['ok' => false, 'error' => 'internal_error'], Outcome::UNKNOWN];
        yield 'delivery failed 502' => [502, ['ok' => false, 'error' => 'provider_error', 'provider_code' => 'E_DELIVERY_FAILED'], Outcome::UNKNOWN];
        yield 'send failed 502' => [502, ['ok' => false, 'error' => 'provider_error', 'provider_code' => 'E_SEND_FAILED'], Outcome::UNKNOWN];
        yield 'non-JSON 502' => [502, null, Outcome::UNKNOWN];
        yield 'non-JSON 200' => [200, null, Outcome::UNKNOWN];
        yield 'non-JSON 404' => [404, null, Outcome::UNAVAILABLE];
        yield '200 not ok' => [200, ['ok' => false], Outcome::UNKNOWN];
    }

    /**
     * @param array<string, mixed>|null $json
     */
    #[DataProvider('replies')]
    public function testReplyClasses(int $status, ?array $json, string $class): void
    {
        self::assertSame($class, Classifier::forReply($status, $json)[0]);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function clientCodes(): iterable
    {
        yield 'not_configured' => ['not_configured', Outcome::UNAVAILABLE];
        yield 'attachments_unsupported' => ['attachments_unsupported', Outcome::UNAVAILABLE];
        yield 'encode_failed' => ['encode_failed', Outcome::UNAVAILABLE];
        yield 'no_recipient' => ['no_recipient', Outcome::REJECTED];
    }

    #[DataProvider('clientCodes')]
    public function testClientCodes(string $code, string $class): void
    {
        self::assertSame($class, Classifier::forCode($code));
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function transport(): iterable
    {
        yield 'C21 connect timeout' => [
            'cURL error 28: Operation timed out after 10001 milliseconds with 0 bytes received',
            Outcome::UNKNOWN,
            'timeout',
        ];
        yield 'C21 connection refused' => [
            'cURL error 7: Failed to connect to gateway.example.org port 443: Connection refused',
            Outcome::UNAVAILABLE,
            'connect_failed',
        ];
        yield 'C21 DNS failure' => ['cURL error 6: Could not resolve host: gateway.example.org', Outcome::UNAVAILABLE, 'dns_failed'];
        yield 'reset' => ['cURL error 56: Recv failure: Connection reset by peer', Outcome::UNKNOWN, 'transport_error_56'];
        yield 'plain timeout text' => ['Operation timed out', Outcome::UNKNOWN, 'timeout'];
        yield 'plain refused text' => ['Connection refused', Outcome::UNAVAILABLE, 'connect_failed'];
        yield 'unknown text' => ['something odd', Outcome::UNKNOWN, 'transport_error'];
    }

    #[DataProvider('transport')]
    public function testTransportClasses(string $message, string $class, string $code): void
    {
        self::assertSame([$class, $code], Classifier::forTransportError($message));
    }
}
