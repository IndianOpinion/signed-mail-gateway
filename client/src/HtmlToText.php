<?php

declare(strict_types=1);

namespace IndianOpinion\SignedMailGateway;

/**
 * A small HTML to plain text converter for the text part of an HTML message
 * (PRD case 68). No dependency: it does not use PHPMailer's converter.
 *
 * It is not a sanitiser and its output is never rendered as HTML. Its job is
 * a readable text part: script, style and other non-content elements are
 * removed with their content, links keep their target, block elements become
 * line breaks, and entities are decoded exactly once, after the tags are
 * gone, so `&lt;b&gt;` reads as `<b>` and is never treated as a tag.
 */
final class HtmlToText
{
    /** Elements removed together with everything inside them. */
    private const DROPPED = ['script', 'style', 'head', 'title', 'template', 'noscript', 'svg', 'object', 'iframe'];

    /** Elements that end a line. */
    private const BLOCKS = 'p|div|h[1-6]|ul|ol|table|tr|blockquote|pre|section|article|header|footer'
        . '|address|dl|dt|dd|form|fieldset|nav|aside|main|figure|figcaption';

    private function __construct()
    {
    }

    public static function convert(string $html): string
    {
        if ($html === '') {
            return '';
        }
        // Work on valid UTF-8 only; invalid sequences become U+FFFD.
        if (function_exists('mb_scrub')) {
            $html = mb_scrub($html, 'UTF-8');
        }
        $text = str_replace(["\r\n", "\r"], "\n", $html);

        // Comments and CDATA, closed or running to the end. Done with string
        // search, not a regex, so no input size can hit a backtracking limit.
        $text = self::cut($text, '/<!--/', '-->');
        $text = self::cut($text, '/<!\[CDATA\[/', ']]>');

        // Non-content elements with their content. An element left open
        // removes everything after it, as a browser would hide it.
        foreach (self::DROPPED as $tag) {
            $text = self::cut($text, '/<' . $tag . '\b[^>]*>/i', '</' . $tag);
            $text = self::replace('/<\/?' . $tag . '\b[^>]*>/i', '', $text);
        }

        // Source formatting is not meaningful in HTML: collapse it first.
        $text = self::replace('/[ \t\n]+/', ' ', $text);

        // Links keep their target when it is a web or mail link and differs from the text.
        $text = self::replaceCallback(
            '/<a\b[^>]*?\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))[^>]*>(.*?)<\/a\s*>/is',
            static function (array $m): string {
                $href = trim(html_entity_decode($m[1] . $m[2] . $m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $label = $m[4];
                $labelText = trim(html_entity_decode(strip_tags($label), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if (preg_match('#^(https?://|mailto:)#i', $href) !== 1) {
                    return $label;
                }
                $shown = preg_replace('#^mailto:#i', '', $href) ?? $href;
                // Re-encoded so the single entity decode below restores it
                // exactly and no character of it can be read as a tag.
                $safe = htmlspecialchars($shown, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($labelText === '' || $labelText === $href || $labelText === $shown) {
                    return ' ' . $safe . ' ';
                }

                return $label . ' (' . $safe . ')';
            },
            $text
        );

        $text = self::replace('/<br\b[^>]*>/i', "\n", $text);
        $text = self::replace('/<hr\b[^>]*>/i', "\n\n", $text);
        $text = self::replace('/<li\b[^>]*>/i', "\n- ", $text);
        $text = self::replace('/<\/li\s*>/i', '', $text);
        $text = self::replace('/<\/?(?:td|th)\b[^>]*>/i', ' ', $text);
        $text = self::replace('/<\/?(?:' . self::BLOCKS . ')\b[^>]*>/i', "\n\n", $text);

        // Every remaining tag, then entities, once.
        $text = self::replace('/<\/?[A-Za-z][^>]*>/', '', $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // No-break spaces read as spaces; control characters other than line breaks go.
        $text = str_replace("\u{00A0}", ' ', $text);
        $text = self::replace('/[\x00-\x09\x0B-\x1F\x7F]/', ' ', $text);

        $text = self::replace('/[ ]+/', ' ', $text);
        $text = self::replace('/ *\n */', "\n", $text);
        $text = self::replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    /**
     * Removes every span from a match of `$open` up to and including the next
     * `$close` (case-insensitive, then up to the following `>` when `$close`
     * is an end tag), or to the end of the text when there is no `$close`.
     */
    private static function cut(string $text, string $open, string $close): string
    {
        $out = '';
        $offset = 0;
        $length = strlen($text);
        while ($offset < $length && preg_match($open, $text, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $start = $m[0][1];
            $out .= substr($text, $offset, $start - $offset);
            $end = stripos($text, $close, $start + strlen($m[0][0]));
            if ($end === false) {
                return $out;
            }
            $end += strlen($close);
            if ($close[0] === '<') {
                $gt = strpos($text, '>', $end);
                if ($gt === false) {
                    return $out;
                }
                $end = $gt + 1;
            }
            $offset = $end;
        }

        return $out . substr($text, $offset);
    }

    private static function replace(string $pattern, string $replacement, string $subject): string
    {
        $out = preg_replace($pattern, $replacement, $subject);

        // A regex failure (backtrack limit) must not leak tags: fall back to strip_tags.
        return is_string($out) ? $out : strip_tags($subject);
    }

    /**
     * @param callable(array<int, string>): string $callback
     */
    private static function replaceCallback(string $pattern, callable $callback, string $subject): string
    {
        $out = preg_replace_callback($pattern, $callback, $subject);

        return is_string($out) ? $out : strip_tags($subject);
    }
}
