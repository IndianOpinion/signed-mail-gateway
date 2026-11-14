<?php

declare(strict_types=1);

namespace IndianOpinion\SignedMailGateway\Tests;

use IndianOpinion\SignedMailGateway\GatewayClient;
use IndianOpinion\SignedMailGateway\Signer;
use PHPUnit\Framework\Attributes\DataProvider;

final class SignerTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function vectors(): array
    {
        $raw = file_get_contents(__DIR__ . '/../../test-vectors/v1.json');
        self::assertIsString($raw);
        $data = json_decode($raw, true);
        self::assertIsArray($data);

        return $data;
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: array<string, string>}>
     */
    public static function vectorProvider(): iterable
    {
        $data = self::vectors();
        foreach ($data['vectors'] as $v) {
            yield $v['id'] => [$v, $data['secrets']];
        }
    }

    /**
     * C35: the signer reproduces every vector in the shared file.
     *
     * @param array<string, mixed>  $v
     * @param array<string, string> $secrets
     */
    #[DataProvider('vectorProvider')]
    public function testSignerReproducesEveryVector(array $v, array $secrets): void
    {
        self::assertSame($v['body_bytes'], strlen($v['body']), 'body length in bytes');
        $expected = Signer::sign($secrets[$v['signed_with']], $v['timestamp'], strtolower($v['nonce']), $v['body']);
        if (str_contains($v['id'], '-with-')) {
            self::assertNotSame($expected, strtolower($v['signature']), 'tampered vector must not verify');
        } else {
            self::assertSame($expected, strtolower($v['signature']));
        }
    }

    /**
     * V10, cross-half: the client's own encoder produces the vector body byte
     * for byte, and that body signs to the vector signature the Worker
     * verifies in worker/test/vectors.test.ts.
     */
    public function testV10ClientEncoderProducesTheSharedBodyByteForByte(): void
    {
        $data = self::vectors();
        $v = null;
        foreach ($data['vectors'] as $candidate) {
            if ($candidate['id'] === 'V10') {
                $v = $candidate;
            }
        }
        self::assertIsArray($v);
        self::assertIsArray($v['message']);
        $body = GatewayClient::encode($v['message']);
        self::assertSame($v['body'], $body);
        self::assertSame($v['body_bytes'], strlen($body));
        self::assertSame(
            $v['signature'],
            Signer::sign($data['secrets']['A'], $v['timestamp'], $v['nonce'], $body)
        );
        $headers = Signer::headers($data['secrets']['A'], $body, (int) $v['timestamp'], $v['nonce']);
        self::assertSame($v['signature'], $headers[Signer::HEADER_SIGNATURE]);
    }

    public function testV1ExactlyAsInThePrd(): void
    {
        $body = '{"from":"Example News <news@mail.example.org>","to":["reader@example.com"],'
            . '"subject":"Hello","text":"Hello, reader."}';
        self::assertSame(
            '5b56c78ceb06a10c7b062c67a66e4a414e3d7c2eb2e15e93ee22cca8b54d353b',
            Signer::sign('gateway-test-secret-A-0123456789abcdef', '1767225600', '0123456789abcdef0123456789abcdef', $body)
        );
    }

    public function testHeadersUseTenDigitTimestampAndFreshLowerCaseNonce(): void
    {
        $a = Signer::headers(self::SECRET, '{}', self::NOW);
        $b = Signer::headers(self::SECRET, '{}', self::NOW);
        self::assertSame('1767225600', $a[Signer::HEADER_TIMESTAMP]);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $a[Signer::HEADER_NONCE]);
        self::assertNotSame($a[Signer::HEADER_NONCE], $b[Signer::HEADER_NONCE]);
        self::assertSame(
            Signer::sign(self::SECRET, $a[Signer::HEADER_TIMESTAMP], $a[Signer::HEADER_NONCE], '{}'),
            $a[Signer::HEADER_SIGNATURE]
        );
    }

    public function testNonAsciiSecretIsUsedAsItsUtf8Bytes(): void
    {
        $secret = 'clé-secrète-ñ-0123456789abcdef-0123456789';
        self::assertSame(
            hash_hmac('sha256', '1767225600.' . str_repeat('ab', 16) . '.{}', $secret),
            Signer::sign($secret, '1767225600', str_repeat('ab', 16), '{}')
        );
    }
}
