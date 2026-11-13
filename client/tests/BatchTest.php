<?php

declare(strict_types=1);

namespace IndianOpinion\SignedMailGateway\Tests;

use IndianOpinion\SignedMailGateway\Outcome;

final class BatchTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function message(string $subject, string $to = 'reader@example.com'): array
    {
        return ['from' => 'news@mail.example.org', 'to' => [$to], 'subject' => $subject, 'text' => 'Body'];
    }

    public function testC26ResultsMapInOrderAndTimeoutIs45(): void
    {
        $this->configure();
        $this->respond(200, ['ok' => true, 'batch' => true, 'n' => 3, 'sent' => 2, 'results' => [
            ['ok' => true, 'id' => 'm1', 'domain' => 'mail.example.org', 'dropped_headers' => [], 'status' => 200],
            ['ok' => false, 'error' => 'bad_recipient', 'message' => 'x', 'status' => 400],
            ['ok' => false, 'error' => 'provider_error', 'provider_code' => 'E_DAILY_LIMIT_EXCEEDED', 'status' => 429],
        ]]);
        $results = signed_mail_gateway_send_batch([self::message('a'), self::message('b'), self::message('c')]);
        self::assertCount(1, \WpStubs::$requests);
        self::assertSame(self::URL . '/v1/batch', \WpStubs::$requests[0]['url']);
        self::assertSame(45, \WpStubs::$requests[0]['args']['timeout']);
        self::assertSame(['a', 'b', 'c'], array_column($this->payload()['messages'], 'subject'));
        self::assertSame([Outcome::SENT, Outcome::REJECTED, Outcome::QUOTA], array_column($results, 'class'));
        self::assertSame('m1', $results[0]['id']);
        self::assertSame(400, $results[1]['status']);
        // Never falls back on its own.
        self::assertSame([], \WpStubs::$coreSent);
    }

    public function testC27EntryWithoutRecipientsIsNotSent(): void
    {
        $this->configure();
        $this->respond(200, ['ok' => true, 'batch' => true, 'n' => 2, 'sent' => 2, 'results' => [
            ['ok' => true, 'id' => 'm1', 'status' => 200],
            ['ok' => true, 'id' => 'm2', 'status' => 200],
        ]]);
        $noTo = self::message('b');
        $noTo['to'] = [];
        $withFile = self::message('d');
        $withFile['attachments'] = ['/tmp/x.pdf'];
        $results = signed_mail_gateway_send_batch([self::message('a'), $noTo, self::message('c'), $withFile]);
        self::assertSame(['a', 'c'], array_column($this->payload()['messages'], 'subject'));
        self::assertSame([Outcome::SENT, Outcome::REJECTED, Outcome::SENT, Outcome::UNAVAILABLE], array_column($results, 'class'));
        self::assertSame(['m1', '', 'm2', ''], array_column($results, 'id'));
        self::assertSame('no_recipient', $results[1]['code']);
        self::assertSame('attachments_unsupported', $results[3]['code']);
        self::assertSame(40, \WpStubs::$requests[0]['args']['timeout']);
    }

    public function testC28Batch404FallsBackToOneSendPerMessage(): void
    {
        $this->configure();
        $this->respondQueue([
            [404, "Not found\n"],
            [200, ['ok' => true, 'id' => 's1']],
            [200, ['ok' => true, 'id' => 's2']],
        ]);
        $results = signed_mail_gateway_send_batch([self::message('a'), self::message('b')]);
        self::assertSame(['/v1/batch', '/v1/send', '/v1/send'], array_map(
            static fn (array $r): string => substr($r['url'], strlen(self::URL)),
            \WpStubs::$requests
        ));
        self::assertSame(['s1', 's2'], array_column($results, 'id'));
        // Each send has its own nonce.
        self::assertNotSame(
            \WpStubs::$requests[1]['args']['headers']['X-Gateway-Nonce'],
            \WpStubs::$requests[2]['args']['headers']['X-Gateway-Nonce']
        );
    }

    public function testC29WholeCall401GivesEveryMessageUnavailable(): void
    {
        $this->configure();
        $this->respond(401, ['ok' => false, 'error' => 'auth_failed']);
        $results = signed_mail_gateway_send_batch([self::message('a'), self::message('b'), self::message('c')]);
        self::assertSame(array_fill(0, 3, Outcome::UNAVAILABLE), array_column($results, 'class'));
        self::assertSame(array_fill(0, 3, 'auth_failed'), array_column($results, 'code'));
    }

    public function testWholeCallTimeoutGivesEveryMessageUnknown(): void
    {
        $this->configure();
        $this->transportError('cURL error 28: timed out');
        $results = signed_mail_gateway_send_batch([self::message('a'), self::message('b')]);
        self::assertSame([Outcome::UNKNOWN, Outcome::UNKNOWN], array_column($results, 'class'));
    }

    public function testC30WrongResultCountGivesEveryMessageUnknown(): void
    {
        $this->configure();
        $this->respond(200, ['ok' => true, 'batch' => true, 'n' => 3, 'sent' => 2, 'results' => [
            ['ok' => true, 'id' => 'm1', 'status' => 200],
            ['ok' => true, 'id' => 'm2', 'status' => 200],
        ]]);
        $results = signed_mail_gateway_send_batch([self::message('a'), self::message('b'), self::message('c')]);
        self::assertSame(array_fill(0, 3, Outcome::UNKNOWN), array_column($results, 'class'));

        $this->respond(200, ['ok' => true, 'batch' => true]);
        $results = signed_mail_gateway_send_batch([self::message('a')]);
        self::assertSame([Outcome::UNKNOWN], array_column($results, 'class'));
    }

    public function testBatchLogsOneAttemptPerMessageWithoutAddresses(): void
    {
        $this->configure();
        $this->respond(200, ['ok' => true, 'batch' => true, 'results' => [
            ['ok' => true, 'id' => 'm1', 'status' => 200],
        ]]);
        signed_mail_gateway_send_batch([self::message('SECRET-SUBJECT')]);
        $lines = $this->attemptLines();
        self::assertCount(1, $lines);
        self::assertSame('batch', $lines[0]['route']);
        self::assertSame(16, strlen((string) $lines[0]['rcpt_hash']));
        self::assertStringNotContainsString('@', implode("\n", $this->logs));
        self::assertStringNotContainsString('SECRET-SUBJECT', implode("\n", $this->logs));
    }

    public function testBatchNotConfiguredGivesEveryMessageUnavailable(): void
    {
        $results = signed_mail_gateway_send_batch([self::message('a'), self::message('b')]);
        self::assertSame(['not_configured', 'not_configured'], array_column($results, 'code'));
        self::assertSame([], \WpStubs::$requests);
    }
}
