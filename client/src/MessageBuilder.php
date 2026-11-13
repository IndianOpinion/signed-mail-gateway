<?php

declare(strict_types=1);

namespace IndianOpinion\SignedMailGateway;

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Builds the gateway message the way core `wp_mail()` builds a PHPMailer
 * message (PRD cases 66 to 73, lesson 18).
 *
 * Same header parsing, the same From, content type and charset filters, then
 * `phpmailer_init` on a private PHPMailer instance that is never sent, so
 * plugins that set the From, a Reply-To or the plain-text part there keep
 * working.
 */
final class MessageBuilder
{
    /** Headers core itself never passes on. */
    private const CORE_SKIPPED = ['mime-version', 'x-mailer'];

    public const PROFILE_HEADER = 'X-Gateway-Profile';

    public function __construct(private readonly Config $config)
    {
    }

    /**
     * @param array<string, mixed>                       $atts    The `wp_mail()` attributes.
     * @param array{email: string, name: string}|null    $profile Sender profile to apply after `phpmailer_init`.
     */
    public function build(array $atts, ?array $profile = null): BuiltMessage
    {
        self::loadPhpMailer();

        $to = $atts['to'] ?? [];
        $subject = is_string($atts['subject'] ?? null) ? $atts['subject'] : '';
        $message = is_string($atts['message'] ?? null) ? $atts['message'] : '';
        $attachments = self::attachmentList($atts['attachments'] ?? []);

        $parsed = self::parseHeaders($atts['headers'] ?? []);
        $fromEmail = $parsed['from_email'];
        $fromName = $parsed['from_name'];

        if ($fromName === '') {
            $fromName = 'WordPress';
        }
        if ($fromEmail === '') {
            $fromEmail = 'wordpress@' . self::siteName();
        }
        $fromEmail = (string) apply_filters('wp_mail_from', $fromEmail);
        $fromName = (string) apply_filters('wp_mail_from_name', $fromName);

        $mailer = new PHPMailer(true);
        $fromSet = true;
        try {
            $mailer->setFrom($fromEmail, $fromName, false);
        } catch (PHPMailerException) {
            $fromSet = false;
        }

        $toList = is_array($to) ? $to : explode(',', is_string($to) ? $to : '');
        $headerLists = [
            'to' => $toList,
            'cc' => $parsed['cc'],
            'bcc' => $parsed['bcc'],
            'reply_to' => $parsed['reply_to'],
        ];
        foreach ($headerLists as $kind => $addresses) {
            foreach ($addresses as $address) {
                if (!is_string($address)) {
                    continue;
                }
                $name = '';
                if (preg_match('/(.*)<(.+)>/', $address, $m) === 1) {
                    $name = $m[1];
                    $address = $m[2];
                }
                try {
                    match ($kind) {
                        'to' => $mailer->addAddress($address, $name),
                        'cc' => $mailer->addCC($address, $name),
                        'bcc' => $mailer->addBCC($address, $name),
                        'reply_to' => $mailer->addReplyTo($address, $name),
                    };
                } catch (PHPMailerException) {
                    // Core skips addresses PHPMailer refuses.
                    continue;
                }
            }
        }

        $mailer->Subject = $subject;
        $mailer->Body = $message;

        $contentType = $parsed['content_type'] !== '' ? $parsed['content_type'] : 'text/plain';
        $contentType = (string) apply_filters('wp_mail_content_type', $contentType);
        $mailer->ContentType = $contentType;
        if ($contentType === 'text/html') {
            $mailer->isHTML(true);
        }
        $charset = $parsed['charset'] !== '' ? $parsed['charset'] : (string) get_bloginfo('charset');
        $mailer->CharSet = (string) apply_filters('wp_mail_charset', $charset);

        foreach ($parsed['custom'] as $name => $content) {
            if (in_array(strtolower($name), self::CORE_SKIPPED, true)) {
                continue;
            }
            try {
                $mailer->addCustomHeader(sprintf('%1$s: %2$s', $name, $content));
            } catch (PHPMailerException) {
                continue;
            }
        }

        do_action_ref_array('phpmailer_init', [&$mailer]);

        // Lesson 19: profiles go after phpmailer_init, where SMTP plugins force their own From.
        if ($profile !== null) {
            $fromEmail = $profile['email'];
            $fromName = $profile['name'];
        } elseif ($fromSet || $mailer->From !== 'root@localhost') {
            $fromEmail = $mailer->From;
            $fromName = $mailer->FromName;
        }

        $charset = strtoupper($mailer->CharSet);
        $convert = static fn (string $s): string => $s;
        if ($charset !== '' && $charset !== 'UTF-8' && $charset !== 'UTF8' && function_exists('mb_convert_encoding')) {
            $convert = static function (string $s) use ($charset): string {
                try {
                    $out = mb_convert_encoding($s, 'UTF-8', $charset);
                } catch (\ValueError) {
                    return $s;
                }

                return is_string($out) ? $out : $s;
            };
        }

        $toAddresses = self::pairs($mailer->getToAddresses());
        $ccAddresses = self::pairs($mailer->getCcAddresses());
        $bccAddresses = self::pairs($mailer->getBccAddresses());
        $replyTo = self::pairs(array_values($mailer->getReplyToAddresses()));

        [$fromEmail, $fromName, $replyTo] = $this->applyDomainPolicy($fromEmail, $convert($fromName), $replyTo);

        $payload = [
            'from' => Address::payload($fromEmail, $fromName),
            'to' => array_map(static fn (array $p): string|array => Address::payload($p[0], $convert($p[1])), $toAddresses),
            'subject' => $convert($mailer->Subject),
        ];
        if ($ccAddresses !== []) {
            $payload['cc'] = array_map(static fn (array $p): string|array => Address::payload($p[0], $convert($p[1])), $ccAddresses);
        }
        if ($bccAddresses !== []) {
            $payload['bcc'] = array_map(static fn (array $p): string|array => Address::payload($p[0], $convert($p[1])), $bccAddresses);
        }
        if ($replyTo !== []) {
            $payload['reply_to'] = Address::payload($replyTo[0][0], $convert($replyTo[0][1]));
        }

        if ($mailer->ContentType === 'text/html') {
            $payload['html'] = $convert($mailer->Body);
            $payload['text'] = $convert($mailer->AltBody !== '' ? $mailer->AltBody : $mailer->html2text($mailer->Body));
        } else {
            $payload['text'] = $convert($mailer->Body);
        }

        [$headers, $dropped] = $this->customHeaders($mailer->getCustomHeaders(), $convert);
        if ($headers !== []) {
            $payload['headers'] = $headers;
        }

        $mailData = [
            'to' => $toList,
            'subject' => $subject,
            'message' => $message,
            'headers' => $parsed['custom'],
            'attachments' => $attachments,
        ];

        return new BuiltMessage(
            $payload,
            $mailData,
            $attachments !== [] || $mailer->getAttachments() !== [],
            count($toAddresses) + count($ccAddresses) + count($bccAddresses),
            $toAddresses[0][0] ?? '',
            $dropped,
        );
    }

    /**
     * Parses headers exactly as core `wp_mail()` does.
     *
     * @return array{from_email: string, from_name: string, content_type: string, charset: string,
     *     cc: list<string>, bcc: list<string>, reply_to: list<string>, custom: array<string, string>}
     */
    public static function parseHeaders(mixed $headers): array
    {
        $out = [
            'from_email' => '',
            'from_name' => '',
            'content_type' => '',
            'charset' => '',
            'cc' => [],
            'bcc' => [],
            'reply_to' => [],
            'custom' => [],
        ];
        if (is_array($headers)) {
            $lines = $headers;
        } elseif (is_string($headers) && $headers !== '') {
            $lines = explode("\n", str_replace("\r\n", "\n", $headers));
        } else {
            $lines = [];
        }

        foreach ($lines as $header) {
            if (!is_string($header) || !str_contains($header, ':')) {
                continue;
            }
            [$name, $content] = explode(':', trim($header), 2);
            $name = trim($name);
            $content = trim($content);

            switch (strtolower($name)) {
                case 'from':
                    $bracket = strpos($content, '<');
                    if ($bracket !== false) {
                        if ($bracket > 0) {
                            $out['from_name'] = trim(str_replace('"', '', substr($content, 0, $bracket)));
                        }
                        $out['from_email'] = trim(str_replace('>', '', substr($content, $bracket + 1)));
                    } elseif ($content !== '') {
                        $out['from_email'] = $content;
                    }
                    break;
                case 'content-type':
                    if (str_contains($content, ';')) {
                        [$type, $rest] = explode(';', $content, 2);
                        $out['content_type'] = trim($type);
                        if (stripos($rest, 'charset=') !== false) {
                            $out['charset'] = trim(str_ireplace(['charset=', '"'], '', $rest));
                        }
                    } elseif ($content !== '') {
                        $out['content_type'] = $content;
                    }
                    break;
                case 'cc':
                    $out['cc'] = array_merge($out['cc'], explode(',', $content));
                    break;
                case 'bcc':
                    $out['bcc'] = array_merge($out['bcc'], explode(',', $content));
                    break;
                case 'reply-to':
                    $out['reply_to'] = array_merge($out['reply_to'], explode(',', $content));
                    break;
                default:
                    $out['custom'][$name] = $content;
                    break;
            }
        }

        return $out;
    }

    /**
     * Removes the profile header from `wp_mail()` headers, in either form.
     *
     * @return array{0: mixed, 1: string|null} The headers without it, and the profile name if one was given.
     */
    public static function extractProfile(mixed $headers): array
    {
        $profile = null;
        $isString = is_string($headers);
        $lines = $isString ? explode("\n", str_replace("\r\n", "\n", $headers)) : $headers;
        if (!is_array($lines)) {
            return [$headers, null];
        }
        $kept = [];
        foreach ($lines as $key => $line) {
            if (is_string($line) && preg_match('/^\s*' . preg_quote(self::PROFILE_HEADER, '/') . '\s*:(.*)$/i', $line, $m) === 1) {
                $profile = trim($m[1]);
                continue;
            }
            $kept[$key] = $line;
        }
        if ($profile === null) {
            return [$headers, null];
        }

        return [$isString ? implode("\n", $kept) : (array_is_list($lines) ? array_values($kept) : $kept), $profile];
    }

    /**
     * Case 71: a From outside the gateway domains becomes the default From.
     *
     * @param list<array{0: string, 1: string}> $replyTo
     *
     * @return array{0: string, 1: string, 2: list<array{0: string, 1: string}>}
     */
    private function applyDomainPolicy(string $fromEmail, string $fromName, array $replyTo): array
    {
        if ($this->config->domains === [] || $this->config->defaultFrom === '') {
            return [$fromEmail, $fromName, $replyTo];
        }
        $valid = Address::isValid($fromEmail);
        if ($valid && $this->config->gatewayDomain(Address::domain($fromEmail))) {
            return [$fromEmail, $fromName, $replyTo];
        }
        $default = Address::parse($this->config->defaultFrom);
        if ($default === null) {
            return [$fromEmail, $fromName, $replyTo];
        }
        if ($valid && $replyTo === [] && !Address::looksNoReply($fromEmail)) {
            $replyTo = [[$fromEmail, $fromName]];
        }

        return [$default['email'], $fromName !== '' ? $fromName : $default['name'], $replyTo];
    }

    /**
     * Custom headers for the payload: core-skipped names, the profile header
     * and operator-stripped prefixes are removed. First occurrence wins.
     *
     * @param array<mixed>               $raw     PHPMailer's custom headers: list of [name, value].
     * @param \Closure(string): string   $convert
     *
     * @return array{0: array<string, string>, 1: int}
     */
    private function customHeaders(array $raw, \Closure $convert): array
    {
        $prefixes = apply_filters('signed_mail_gateway_strip_header_prefixes', ['X-SES-']);
        $prefixes = is_array($prefixes) ? array_values(array_filter(array_map(
            static fn (mixed $p): string => is_string($p) ? strtolower(trim($p)) : '',
            $prefixes
        ), static fn (string $p): bool => $p !== '')) : [];

        $out = [];
        $seen = [];
        $dropped = 0;
        foreach ($raw as $pair) {
            if (!is_array($pair) || !is_string($pair[0] ?? null) || !is_string($pair[1] ?? null)) {
                continue;
            }
            $name = trim($pair[0]);
            $lower = strtolower($name);
            if (
                in_array($lower, self::CORE_SKIPPED, true)
                || $lower === strtolower(self::PROFILE_HEADER)
                || isset($seen[$lower])
            ) {
                continue;
            }
            foreach ($prefixes as $prefix) {
                if (str_starts_with($lower, $prefix)) {
                    $dropped++;
                    continue 2;
                }
            }
            $seen[$lower] = true;
            $out[$name] = $convert($pair[1]);
        }

        return [$out, $dropped];
    }

    /**
     * @param array<mixed> $list PHPMailer's [address, name] pairs.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function pairs(array $list): array
    {
        $out = [];
        foreach ($list as $pair) {
            if (is_array($pair) && is_string($pair[0] ?? null)) {
                $out[] = [$pair[0], is_string($pair[1] ?? null) ? $pair[1] : ''];
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function attachmentList(mixed $attachments): array
    {
        if (is_string($attachments)) {
            $attachments = explode("\n", str_replace("\r\n", "\n", $attachments));
        }
        if (!is_array($attachments)) {
            return [];
        }
        $out = [];
        foreach ($attachments as $a) {
            if (is_string($a) && trim($a) !== '') {
                $out[] = $a;
            }
        }

        return $out;
    }

    private static function siteName(): string
    {
        $host = wp_parse_url(network_home_url(), PHP_URL_HOST);
        $host = is_string($host) ? strtolower($host) : '';
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return $host;
    }

    private static function loadPhpMailer(): void
    {
        if (class_exists(PHPMailer::class)) {
            return;
        }
        if (defined('ABSPATH') && defined('WPINC')) {
            $dir = constant('ABSPATH') . constant('WPINC') . '/PHPMailer/';
            require_once $dir . 'PHPMailer.php';
            require_once $dir . 'Exception.php';
        }
    }
}
