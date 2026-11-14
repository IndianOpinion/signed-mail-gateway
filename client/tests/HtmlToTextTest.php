<?php

declare(strict_types=1);

namespace IndianOpinion\SignedMailGateway\Tests;

use IndianOpinion\SignedMailGateway\HtmlToText;
use PHPUnit\Framework\Attributes\DataProvider;

final class HtmlToTextTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function cases(): iterable
    {
        yield 'paragraphs and breaks' => ['<p>One</p><p>Two<br>Three</p>', "One\n\nTwo\nThree"];
        yield 'source whitespace collapses' => ["<p>One\n   two\tthree</p>", 'One two three'];
        yield 'script removed with content' => ['<p>Hi</p><script>alert("x")</script><p>there</p>', "Hi\n\nthere"];
        yield 'script any case, attributes, spaced close' => ['A<SCRIPT type="text/javascript">x()</script >B', 'AB'];
        yield 'script containing a closing-looking string' => ['A<script>var s = "</scr" + "ipt>";</script>B', 'AB'];
        yield 'unclosed script hides the rest' => ['Visible<script>steal(document.cookie)', 'Visible'];
        yield 'style and head removed' => ['<head><title>T</title><style>p{color:red}</style></head><p>Body</p>', 'Body'];
        yield 'comments removed, even unclosed' => ['A<!-- hidden <b>x</b> -->B<!-- open', 'AB'];
        yield 'entities decoded once' => ['&lt;b&gt;not a tag&lt;/b&gt; &amp;lt;', '<b>not a tag</b> &lt;'];
        yield 'encoded script stays text' => ['&lt;script&gt;alert(1)&lt;/script&gt;', '<script>alert(1)</script>'];
        yield 'numeric and named entities' => ['Caf&#233; &#x41; &eacute; &quot;q&quot; &#39;s', "Café A é \"q\" 's"];
        yield 'no-break space' => ['a&nbsp;b', 'a b'];
        yield 'unknown entity kept' => ['&notanentity; &', '&notanentity; &'];
        yield 'link keeps target' => [
            '<a href="https://example.org/x?a=1&amp;b=2">Sign in</a>',
            'Sign in (https://example.org/x?a=1&b=2)',
        ];
        yield 'link text equal to target' => ['<a href="https://example.org/">https://example.org/</a>', 'https://example.org/'];
        yield 'mailto shown as address' => ["<a href='mailto:help@example.org'>help@example.org</a>", 'help@example.org'];
        yield 'javascript link drops target' => ['<a href="javascript:alert(1)">Click</a>', 'Click'];
        yield 'target with angle brackets cannot become a tag' => [
            '<a href="https://e.example/&lt;script&gt;">x</a>',
            'x (https://e.example/<script>)',
        ];
        yield 'lists' => ['<ul><li>One</li><li>Two</li></ul>', "- One\n- Two"];
        yield 'table cells' => ['<table><tr><td>A</td><td>B</td></tr></table>', 'A B'];
        yield 'less-than in text' => ['1 < 2 and 3 > 2', '1 < 2 and 3 > 2'];
        yield 'control characters removed' => ["a\x00b\x1Bc", 'a b c'];
        yield 'empty' => ['', ''];
    }

    #[DataProvider('cases')]
    public function testConverts(string $html, string $expected): void
    {
        self::assertSame($expected, HtmlToText::convert($html));
    }

    public function testInvalidUtf8DoesNotBreakTheResult(): void
    {
        $out = HtmlToText::convert("<p>ok \xFF\xFE end</p>");
        self::assertSame(1, preg_match('//u', $out));
        self::assertStringContainsString('ok', $out);
        self::assertStringContainsString('end', $out);
    }

    public function testLargeInputStaysBoundedAndTagFree(): void
    {
        $html = str_repeat('<div><script>x</script><p>a &amp; b</p></div>', 5000);
        $out = HtmlToText::convert($html);
        self::assertStringNotContainsString('<', $out);
        self::assertStringNotContainsString('x', $out);
        self::assertStringContainsString('a & b', $out);
    }

    public function testUnclosedScriptAtGatewaySizeLimitStillHidesTheRest(): void
    {
        $html = '<p>Visible</p><script>' . str_repeat('leak();', 600000);
        self::assertSame('Visible', HtmlToText::convert($html));
    }
}
