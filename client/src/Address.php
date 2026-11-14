<?php

declare(strict_types=1);

namespace IndianOpinion\SignedMailGateway;

/**
 * Address helpers that match the gateway's own rules, so a message the
 * client builds is not refused for a reason the client could have seen.
 */
final class Address
{
    private const MAX_EMAIL = 254;
    private const MAX_NAME = 100;
    private const LOCAL = "[A-Za-z0-9!#$%&'*+\\/=?^_`{|}~-]+(?:\\.[A-Za-z0-9!#$%&'*+\\/=?^_`{|}~-]+)*";
    private const LABEL = '[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?';

    private function __construct()
    {
    }

    public static function isValid(string $email): bool
    {
        if ($email === '' || strlen($email) > self::MAX_EMAIL || strpbrk($email, "\r\n") !== false) {
            return false;
        }
        $pattern = '/^' . self::LOCAL . '@' . self::LABEL . '(?:\.' . self::LABEL . ')+$/D';

        return preg_match($pattern, $email) === 1;
    }

    /**
     * Parses `a@b`, `Name <a@b>` or `"Name" <a@b>`.
     *
     * @return array{email: string, name: string}|null
     */
    public static function parse(string $raw): ?array
    {
        if (strpbrk($raw, "\r\n") !== false) {
            return null;
        }
        $raw = trim($raw);
        $name = '';
        if (preg_match('/^(.*)<([^<>]+)>$/s', $raw, $m) === 1) {
            $name = $m[1];
            $email = trim($m[2]);
        } else {
            $email = $raw;
        }
        if (!self::isValid($email)) {
            return null;
        }

        return ['email' => $email, 'name' => self::cleanName($name)];
    }

    /** CR, LF and tab become spaces, quotes are removed, the result is trimmed and cut to 100 characters. */
    public static function cleanName(string $name): string
    {
        $name = trim(str_replace(["\r", "\n", "\t", '"'], [' ', ' ', ' ', ''], $name));
        if (function_exists('mb_substr')) {
            return trim(mb_substr($name, 0, self::MAX_NAME, 'UTF-8'));
        }

        return trim(substr($name, 0, self::MAX_NAME));
    }

    public static function domain(string $email): string
    {
        $at = strrpos($email, '@');

        return $at === false ? '' : strtolower(substr($email, $at + 1));
    }

    /** True for addresses nobody reads: no-reply, do-not-reply, mailer-daemon, bounces, WordPress's default. */
    public static function looksNoReply(string $email): bool
    {
        $at = strrpos($email, '@');
        $local = strtolower($at === false ? $email : substr($email, 0, $at));

        return preg_match('/^(no[-_.]?reply|do[-_.]?not[-_.]?reply|mailer[-_.]?daemon|bounces?|wordpress)([-+_.].*)?$/', $local) === 1;
    }

    /**
     * The payload form: a bare string when there is no name, else an object.
     *
     * @return string|array{email: string, name: string}
     */
    public static function payload(string $email, string $name): string|array
    {
        $name = self::cleanName($name);

        return $name === '' ? $email : ['email' => $email, 'name' => $name];
    }
}
