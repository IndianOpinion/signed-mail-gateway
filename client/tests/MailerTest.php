<?php

declare(strict_types=1);

namespace IndianOpinion\SignedMailGateway\Tests;

use IndianOpinion\SignedMailGateway\Config;
use IndianOpinion\SignedMailGateway\Outcome;
use IndianOpinion\SignedMailGateway\Signer;
use PHPMailer\PHPMailer\PHPMailer;
use PHPUnit\Framework\Attributes\DataProvider;

final class MailerTest extends TestCase
{
    public function testC1EarlierFilterAnswerIsReturnedAndNothingIsSent(): void
    {
        $this->configure();
        $this->sentOk();
        add_filter('pre_wp_mail', static fn (): bool => false, 5);
        self::assertFalse(wp_mail('reader@example.com', 'Hi', 'Body'));
        self::assertSame([], \WpStubs::$requests);
        self::assertSame([], \WpStubs::$coreSent);
    }

    public function testC2ProviderWordpressLetsCoreSend(): void
    {
        $this->configure();
        update_option(Config::OPTION_PROVIDER, 'wordpress');
        \WpStubs::$coreSender = static fn (): bool => false;
        self::assertFalse(wp_mail('reader@example.com', 'Hi', 'Body'));
        self::assertSame([], \WpStubs::$requests);
        self::assertCount(1, \WpStubs::$coreSent);
        // Case 83: a core failure never tries the gateway.
        self::assertSame([], \WpStubs::$requests);
    }

    public function testC3NotConfiguredLogsAndFallsBack(): void
    {
        self::assertTrue(wp_mail('reader@example.com', 'Hi', 'Body'));
        self::assertCount(1, \WpStubs::$coreSent);
        self::assertSame([], \WpStubs::$requests);
        self::assertStringContainsString('"code":"not_configured"', implode("\n", $this->logs));
        self::assertSame('missing_url', $this->noticeField('not_configured', 'detail'));
    }

    public function testC3NotConfiguredWithFallbackOffFails(): void
    {
        update_option(Config::OPTION_FALLBACK, '0');
        self::assertFalse(wp_mail('reader@example.com', 'Hi', 'Body'));
        self::assertSame([], \WpStubs::$coreSent);
        self::assertSame('not_configured', signed_mail_gateway_last_result()['code']);
    }

    public function testC4BypassFilterLetsCoreSend(): void
    {
        $this->configure();
        $this->sentOk();
        add_filter('signed_mail_gateway_bypass', static fn (): bool => true);
        self::assertTrue(wp_mail('reader@example.com', 'Hi', 'Body'));
        self::assertSame([], \WpStubs::$requests);
        self::assertCount(1, \WpStubs::$coreSent);
    }

    public function testC5HeadersAreParsedAsCoreDoes(): void
    {
        $this->configure();
        $this->sentOk();
        self::assertTrue(wp_mail('reader@example.com', 'Hi', '<p>Hello</p>', [
            'From: "Ann Example" <ann@mail.example.org>',
            'Content-Type: text/html; charset=UTF-8',
            'Cc: c1@example.com, Cee <c2@example.com>',
            'Bcc: b@example.com',
            'Reply-To: r@example.com',
            'X-Custom: one',
            'List-Id: <news.mail.example.org>',
        ]));
        $p = $this->payload();
        self::assertSame(['email' => 'ann@mail.example.org', 'name' => 'Ann Example'], $p['from']);
        self::assertSame(['reader@example.com'], $p['to']);
        self::assertSame(['c1@example.com', ['email' => 'c2@example.com', 'name' => 'Cee']], $p['cc']);
        self::assertSame(['b@example.com'], $p['bcc']);
        self::assertSame('r@example.com', $p['reply_to']);
        self::assertSame('<p>Hello</p>', $p['html']);
        self::assertSame(['X-Custom' => 'one', 'List-Id' => '<news.mail.example.org>'], $p['headers']);
    }

    public function testHeadersAsStringWithCrlfAreParsed(): void
    {
        $this->configure();
        $this->sentOk();
        wp_mail('reader@example.com', 'Hi', 'Body', "From: ann@mail.example.org\r\nX-Custom: two\r\n");
        $p = $this->payload();
        self::assertSame(['email' => 'ann@mail.example.org', 'name' => 'WordPress'], $p['from']);
        self::assertSame(['X-Custom' => 'two'], $p['headers']);
    }

    public function testC6PhpmailerInitChangesAreReflected(): void
    {
        $this->configure();
        $this->sentOk();
        add_action('phpmailer_init', static function (PHPMailer $m): void {
            $m->AltBody = 'Plain alternative';
            $m->addReplyTo('help@example.com', 'Help');
        });
        wp_mail('reader@example.com', 'Hi', '<p>Hello</p>', ['Content-Type: text/html', 'From: ann@mail.example.org']);
        $p = $this->payload();
        self::assertSame('Plain alternative', $p['text']);
        self::assertSame(['email' => 'help@example.com', 'name' => 'Help'], $p['reply_to']);
    }

    public function testC7StringToIsSplitOnCommas(): void
    {
        $this->configure();
        $this->sentOk();
        wp_mail('a@example.com, Bee <b@example.com>, not-an-address', 'Hi', 'Body', 'From: ann@mail.example.org');
        self::assertSame(['a@example.com', ['email' => 'b@example.com', 'name' => 'Bee']], $this->payload()['to']);
    }

    public function testC8HtmlWithoutAltBodyGetsTextConversion(): void
    {
        $this->configure();
        $this->sentOk();
        wp_mail('reader@example.com', 'Hi', '<html><body><p>Your code is <b>123456</b></p></body></html>', [
            'Content-Type: text/html',
            'From: ann@mail.example.org',
        ]);
        $p = $this->payload();
        self::assertIsString($p['text']);
        self::assertStringContainsString('Your code is 123456', $p['text']);
        self::assertStringNotContainsString('<', $p['text']);
    }

    public function testPlainTextMessageHasNoHtml(): void
    {
        $this->configure();
        $this->sentOk();
        wp_mail('reader@example.com', 'Hi', 'Body', 'From: ann@mail.example.org');
        self::assertArrayNotHasKey('html', $this->payload());
        self::assertSame('Body', $this->payload()['text']);
    }

    public function testC9MimeVersionAndXMailerAreNotPassedOn(): void
    {
        $this->configure();
        $this->sentOk();
        wp_mail('reader@example.com', 'Hi', 'Body', ['MIME-Version: 1.0', 'X-Mailer: Something', 'X-Keep: yes']);
        self::assertSame(['X-Keep' => 'yes'], $this->payload()['headers']);
    }

    public function testC10AttachmentsSkipTheGatewayAndFallBack(): void
    {
        $this->configure();
        $this->sentOk();
        self::assertTrue(wp_mail('reader@example.com', 'Hi', 'Body', '', ['/tmp/file.pdf']));
        self::assertSame([], \WpStubs::$requests);
        self::assertCount(1, \WpStubs::$coreSent);
        self::assertSame('attachments_unsupported', $this->attemptLines()[0]['code']);
    }

    public function testC11ForeignFromBecomesDefaultFromWithReplyTo(): void
    {
        $this->configure();
        $this->sentOk();
        wp_mail('reader@example.com', 'Hi', 'Body', 'From: Someone <someone@other.example>');
        $p = $this->payload();
        self::assertSame(['email' => 'news@mail.example.org', 'name' => 'Someone'], $p['from']);
        self::assertSame(['email' => 'someone@other.example', 'name' => 'Someone'], $p['reply_to']);
    }

    public function testC11NoReplyOriginalDoesNotBecomeReplyTo(): void
    {
        $this->configure();
        $this->sentOk();
        wp_mail('reader@example.com', 'Hi', 'Body', 'From: noreply@other.example');
        $p = $this->payload();
        self::assertSame(['email' => 'news@mail.example.org', 'name' => 'WordPress'], $p['from']);
        self::assertArrayNotHasKey('reply_to', $p);
    }

    public function testC11ExistingReplyToIsKept(): void
    {
        $this->configure();
        $this->sentOk();
        wp_mail('reader@example.com', 'Hi', 'Body', ['From: someone@other.example', 'Reply-To: desk@example.com']);
        self::assertSame('desk@example.com', $this->payload()['reply_to']);
    }

    public function testC12KnownProfileSetsFromAfterPhpmailerInit(): void
    {
        $this->configure();
        $this->sentOk();
        add_filter('signed_mail_gateway_profiles', static fn (): array => [
            'alerts' => ['email' => 'alerts@mail.example.org', 'name' => 'Alerts'],
        ]);
        // An SMTP plugin forcing its own From inside phpmailer_init.
        add_action('phpmailer_init', static function (PHPMailer $m): void {
            $m->setFrom('smtp@mail.example.org', 'SMTP', false);
        });
        wp_mail('reader@example.com', 'Hi', 'Body', ['X-Gateway-Profile: alerts', 'X-Other: 1']);
        $p = $this->payload();
        self::assertSame(['email' => 'alerts@mail.example.org', 'name' => 'Alerts'], $p['from']);
        self::assertSame(['X-Other' => '1'], $p['headers']);
        self::assertSame('alerts', $this->attemptLines()[0]['profile']);
    }

    public function testC12ProfileHeaderIsRemovedOnTheCorePathToo(): void
    {
        $this->configure();
        update_option(Config::OPTION_PROVIDER, 'wordpress');
        add_filter('signed_mail_gateway_profiles', static fn (): array => [
            'alerts' => ['email' => 'alerts@mail.example.org', 'name' => 'Alerts'],
        ]);
        add_action('phpmailer_init', static function (PHPMailer $m): void {
            $m->setFrom('smtp@mail.example.org', 'SMTP', false);
        });
        wp_mail('reader@example.com', 'Hi', 'Body', "X-Gateway-Profile: alerts\nX-Other: 1");
        $mailer = \WpStubs::$coreSent[0]['mailer'];
        self::assertSame('alerts@mail.example.org', $mailer->From);
        $names = array_map(static fn (array $h): string => (string) $h[0], $mailer->getCustomHeaders());
        self::assertSame(['X-Other'], $names);
    }

    public function testC12UnknownProfileIsLoggedAndIgnored(): void
    {
        $this->configure();
        $this->sentOk();
        wp_mail('reader@example.com', 'Hi', 'Body', ['From: ann@mail.example.org', 'X-Gateway-Profile: nobody']);
        $p = $this->payload();
        self::assertSame(['email' => 'ann@mail.example.org', 'name' => 'WordPress'], $p['from']);
        self::assertArrayNotHasKey('headers', $p);
        self::assertSame('nobody', $this->noticeField('unknown_profile', 'profile'));
    }

    public function testC13StrippedPrefixesLeaveTheGatewayPayloadOnly(): void
    {
        $this->configure();
        $this->respond(503, ['ok' => false, 'error' => 'misconfigured']);
        self::assertTrue(wp_mail('reader@example.com', 'Hi', 'Body', ['X-SES-Tags: a=b', 'X-Keep: 1']));
        self::assertSame(['X-Keep' => '1'], $this->payload()['headers']);
        $names = array_map(static fn (array $h): string => (string) $h[0], \WpStubs::$coreSent[0]['mailer']->getCustomHeaders());
        self::assertContains('X-SES-Tags', $names);
        self::assertSame(1, $this->attemptLines()[0]['dropped']);
    }

    public function testC14SignedBytesAreTheSentBytes(): void
    {
        $this->configure();
        $this->sentOk();
        wp_mail('reader@example.com', 'नमस्ते / Café', "Body with / slash and é\n", 'From: ann@mail.example.org');
        $args = \WpStubs::$requests[0]['args'];
        $body = $args['body'];
        self::assertIsString($body);
        $h = $args['headers'];
        self::assertSame(Signer::sign(self::SECRET, $h['X-Gateway-Timestamp'], $h['X-Gateway-Nonce'], $body), $h['X-Gateway-Signature']);
        // Non-ASCII escaped, slashes not escaped.
        self::assertStringContainsString('\\u0928', $body);
        self::assertStringContainsString(' / ', $body);
        self::assertSame(1, preg_match('/^[\x20-\x7e]*$/', $body));
    }

    public function testC15RequestShape(): void
    {
        $this->configure();
        $this->sentOk();
        wp_mail('reader@example.com', 'Hi', 'Body');
        $req = \WpStubs::$requests[0];
        self::assertSame(self::URL . '/v1/send', $req['url']);
        self::assertSame(10, $req['args']['timeout']);
        self::assertSame(0, $req['args']['redirection']);
        self::assertSame('POST', $req['args']['method']);
        self::assertStringStartsWith('signed-mail-gateway/0.1.0', $req['args']['user-agent']);
        $h = $req['args']['headers'];
        self::assertSame('application/json', $h['Content-Type']);
        self::assertMatchesRegularExpression('/^\d{10}$/', $h['X-Gateway-Timestamp']);
        self::assertSame((string) self::NOW, $h['X-Gateway-Timestamp']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $h['X-Gateway-Nonce']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $h['X-Gateway-Signature']);
    }

    public function testTimeoutFilter(): void
    {
        $this->configure();
        $this->sentOk();
        add_filter('signed_mail_gateway_timeout', static fn (int $t, string $route): int => $route === '/v1/send' ? 4 : $t, 10, 2);
        wp_mail('reader@example.com', 'Hi', 'Body');
        self::assertSame(4, \WpStubs::$requests[0]['args']['timeout']);
    }

    public function testPayloadFilterRunsBeforeEncoding(): void
    {
        $this->configure();
        $this->sentOk();
        add_filter('signed_mail_gateway_payload', static function (array $p): array {
            $p['headers'] = ['List-Id' => '<x.example.org>'];

            return $p;
        });
        wp_mail('reader@example.com', 'Hi', 'Body');
        self::assertSame(['List-Id' => '<x.example.org>'], $this->payload()['headers']);
    }

    public function testC16NothingSensitiveLeaksIntoLogsActionsOrErrors(): void
    {
        $this->configure();
        $failed = [];
        add_action('wp_mail_failed', static function (\WP_Error $e) use (&$failed): void {
            $failed[] = $e->get_error_message();
        });
        $attempts = [];
        add_action('signed_mail_gateway_attempt', static function (array $f) use (&$attempts): void {
            $attempts[] = $f;
        });
        $results = [];
        add_action('signed_mail_gateway_result', static function (array $r) use (&$results): void {
            $results[] = $r;
        });

        $this->sentOk();
        wp_mail('Secret Reader <secret.reader@example.com>', 'SECRET-SUBJECT', 'SECRET-BODY', 'From: ann@mail.example.org');
        $this->respond(400, ['ok' => false, 'error' => 'bad_recipient', 'message' => 'a recipient is missing or invalid']);
        wp_mail('secret.reader@example.com', 'SECRET-SUBJECT', 'SECRET-BODY');
        $this->respond(401, ['ok' => false, 'error' => 'auth_failed']);
        \WpStubs::$coreSender = static fn (): bool => false;
        wp_mail('secret.reader@example.com', 'SECRET-SUBJECT', 'SECRET-BODY', 'X-Gateway-Profile: missing');
        $this->respond(401, ['ok' => false, 'error' => 'stale_timestamp', 'server_time' => self::NOW - 400]);
        wp_mail('secret.reader@example.com', 'SECRET-SUBJECT', 'SECRET-BODY');

        $ours = array_filter($failed, static fn (string $m): bool => str_starts_with($m, 'signed-mail-gateway'));
        self::assertNotEmpty($ours);
        $haystack = implode("\n", $this->logs) . json_encode($attempts) . json_encode($results) . implode("\n", $ours);
        foreach ([self::SECRET, 'SECRET-SUBJECT', 'SECRET-BODY', '@', 'Secret Reader', 'secret.reader'] as $needle) {
            self::assertStringNotContainsString($needle, $haystack);
        }
        // No signature or nonce either.
        foreach (\WpStubs::$requests as $req) {
            self::assertStringNotContainsString($req['args']['headers']['X-Gateway-Signature'], $haystack);
            self::assertStringNotContainsString($req['args']['headers']['X-Gateway-Nonce'], $haystack);
        }
    }

    /**
     * @return iterable<string, array{0: int, 1: array<string, mixed>|null, 2: bool}>
     */
    public static function outcomes(): iterable
    {
        yield 'sent' => [200, ['ok' => true, 'id' => 'msg-9'], true];
        yield 'rejected' => [400, ['ok' => false, 'error' => 'bad_subject'], false];
        yield 'suppressed' => [422, ['ok' => false, 'error' => 'provider_error', 'provider_code' => 'E_RECIPIENT_SUPPRESSED'], false];
        yield 'unknown' => [500, ['ok' => false, 'error' => 'internal_error'], false];
    }

    /**
     * C18: true only for sent. C20: suppressed, rejected and unknown never fall back.
     *
     * @param array<string, mixed>|null $body
     */
    #[DataProvider('outcomes')]
    public function testC18AndC20(int $status, ?array $body, bool $expected): void
    {
        $this->configure();
        $this->respond($status, $body ?? '');
        self::assertSame($expected, wp_mail('reader@example.com', 'Hi', 'Body'));
        self::assertSame([], \WpStubs::$coreSent);
        self::assertCount(1, $this->attemptLines());
    }

    public function testC20TimeoutIsUnknownAndNeverFallsBack(): void
    {
        $this->configure();
        $this->transportError('cURL error 28: Operation timed out after 10000 milliseconds');
        self::assertFalse(wp_mail('reader@example.com', 'Hi', 'Body'));
        self::assertSame([], \WpStubs::$coreSent);
        self::assertSame(Outcome::UNKNOWN, signed_mail_gateway_last_result()['class']);
    }

    /**
     * @return iterable<string, array{0: int, 1: array<string, mixed>, 2: string}>
     */
    public static function fallbackCases(): iterable
    {
        yield 'unavailable' => [401, ['ok' => false, 'error' => 'auth_failed'], Outcome::UNAVAILABLE];
        yield 'quota' => [429, ['ok' => false, 'error' => 'provider_error', 'provider_code' => 'E_DAILY_LIMIT_EXCEEDED'], Outcome::QUOTA];
        yield 'retry' => [409, ['ok' => false, 'error' => 'replay'], Outcome::RETRY];
    }

    /**
     * C19: each of unavailable, quota and retry falls back once; core success makes the call true.
     *
     * @param array<string, mixed> $body
     */
    #[DataProvider('fallbackCases')]
    public function testC19FallsBackOnce(int $status, array $body, string $class): void
    {
        $this->configure();
        $this->respond($status, $body);
        self::assertTrue(wp_mail('reader@example.com', 'Hi', 'Body'));
        self::assertCount(1, \WpStubs::$requests);
        self::assertCount(1, \WpStubs::$coreSent);
        $lines = $this->attemptLines();
        self::assertCount(2, $lines);
        self::assertSame($class, $lines[0]['class']);
        self::assertSame('wordpress', $lines[1]['path']);
        self::assertSame(Outcome::SENT, signed_mail_gateway_last_result()['class']);
        self::assertSame('wordpress', signed_mail_gateway_last_result()['path']);
    }

    public function testC21ConnectionRefusedFallsBack(): void
    {
        $this->configure();
        $this->transportError('cURL error 7: Failed to connect: Connection refused');
        self::assertTrue(wp_mail('reader@example.com', 'Hi', 'Body'));
        self::assertCount(1, \WpStubs::$coreSent);
    }

    public function testFallbackClassesFilter(): void
    {
        $this->configure();
        $this->respond(409, ['ok' => false, 'error' => 'replay']);
        add_filter('signed_mail_gateway_fallback_classes', static fn (): array => [Outcome::UNAVAILABLE]);
        self::assertFalse(wp_mail('reader@example.com', 'Hi', 'Body'));
        self::assertSame([], \WpStubs::$coreSent);
    }

    public function testC22GuardIsClearedEvenWhenCoreThrows(): void
    {
        $this->configure();
        $this->respond(503, ['ok' => false, 'error' => 'nonce_store_unavailable']);
        \WpStubs::$coreSender = static function (): bool {
            throw new \RuntimeException('core blew up');
        };
        self::assertFalse(wp_mail('reader@example.com', 'Hi', 'Body'));
        self::assertSame('wordpress', signed_mail_gateway_last_result()['path']);

        // The next message goes to the gateway again: the guard is down.
        $this->sentOk();
        self::assertTrue(wp_mail('reader@example.com', 'Hi', 'Body'));
        self::assertCount(2, \WpStubs::$requests);
    }

    public function testC23FallbackFailureKeepsTheGatewayVerdict(): void
    {
        $this->configure();
        $this->respond(403, ['ok' => false, 'error' => 'domain_disabled']);
        \WpStubs::$coreSender = static fn (): bool => false;
        self::assertFalse(wp_mail('reader@example.com', 'Hi', 'Body'));
        $last = signed_mail_gateway_last_result();
        self::assertSame('domain_disabled', $last['code']);
        self::assertSame(Outcome::UNAVAILABLE, $last['class']);
        self::assertSame(2, $last['attempts']);
        $lines = $this->attemptLines();
        self::assertCount(2, $lines);
        self::assertSame('wp_mail_failed', $lines[1]['code']);
        self::assertSame('failed', $lines[1]['class']);
    }

    public function testC24MailEventsFireWithCoreShape(): void
    {
        $this->configure();
        $this->sentOk();
        wp_mail(['reader@example.com'], 'Hi', 'Body', ['X-Custom: 1']);
        $ok = $this->actionArgs('wp_mail_succeeded');
        self::assertCount(1, $ok);
        self::assertSame(
            ['to' => ['reader@example.com'], 'subject' => 'Hi', 'message' => 'Body', 'headers' => ['X-Custom' => '1'], 'attachments' => []],
            $ok[0][0]
        );

        $this->respond(400, ['ok' => false, 'error' => 'no_body']);
        wp_mail('reader@example.com', 'Hi', 'Body');
        $failed = $this->actionArgs('wp_mail_failed');
        self::assertCount(1, $failed);
        $error = $failed[0][0];
        self::assertInstanceOf(\WP_Error::class, $error);
        self::assertSame('wp_mail_failed', $error->get_error_code());
        self::assertStringContainsString('no_body', $error->get_error_message());
        $data = $error->get_error_data();
        self::assertIsArray($data);
        self::assertSame(['to', 'subject', 'message', 'headers', 'attachments', 'phpmailer_exception_code'], array_keys($data));
    }

    public function testC25LastResultExposesClassPathIdAttempts(): void
    {
        $this->configure();
        self::assertSame([], signed_mail_gateway_last_result());
        $this->sentOk('provider-id-7');
        wp_mail('reader@example.com', 'Hi', 'Body');
        $last = signed_mail_gateway_last_result();
        self::assertSame(Outcome::SENT, $last['class']);
        self::assertSame('gateway', $last['path']);
        self::assertSame('provider-id-7', $last['id']);
        self::assertSame(1, $last['attempts']);
        self::assertSame(200, $last['status']);
    }

    public function testC31StaleTimestampLogsTheClockDifference(): void
    {
        $this->configure();
        update_option(Config::OPTION_FALLBACK, '0');
        $this->respond(401, ['ok' => false, 'error' => 'stale_timestamp', 'server_time' => self::NOW - 412]);
        self::assertFalse(wp_mail('reader@example.com', 'Hi', 'Body'));
        self::assertSame(412, $this->noticeField('clock_difference', 'diff_s'));
        self::assertSame('origin clock is 412 s fast', $this->noticeField('clock_difference', 'detail'));
        self::assertSame(Outcome::RETRY, signed_mail_gateway_last_result()['class']);
    }

    public function testC32NonHttpsUrlIsNotConfigured(): void
    {
        $this->configure('http://gateway.example.org');
        update_option(Config::OPTION_FALLBACK, '0');
        $this->sentOk();
        self::assertFalse(wp_mail('reader@example.com', 'Hi', 'Body'));
        self::assertSame([], \WpStubs::$requests);
        self::assertSame('not_configured', signed_mail_gateway_last_result()['code']);
        self::assertSame('insecure_url', $this->noticeField('not_configured', 'detail'));
    }

    public function testShortSecretIsNotConfigured(): void
    {
        $this->configure(self::URL, str_repeat('x', 31));
        update_option(Config::OPTION_FALLBACK, '0');
        self::assertFalse(wp_mail('reader@example.com', 'Hi', 'Body'));
        self::assertSame('short_secret', $this->noticeField('not_configured', 'detail'));
    }

    public function testNoValidRecipientIsRejectedWithoutFallback(): void
    {
        $this->configure();
        $this->sentOk();
        self::assertFalse(wp_mail('not-an-address', 'Hi', 'Body'));
        self::assertSame([], \WpStubs::$requests);
        self::assertSame([], \WpStubs::$coreSent);
        self::assertSame('no_recipient', signed_mail_gateway_last_result()['code']);
    }

    private function noticeField(string $code, string $field): mixed
    {
        foreach ($this->logs as $line) {
            $decoded = json_decode(substr($line, strlen('signed-mail-gateway: ')), true);
            if (is_array($decoded) && ($decoded['event'] ?? '') === 'notice' && ($decoded['code'] ?? '') === $code) {
                return $decoded[$field] ?? null;
            }
        }
        self::fail('no notice ' . $code);
    }
}
