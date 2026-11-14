<?php

declare(strict_types=1);

namespace IndianOpinion\SignedMailGateway\Tests;

use IndianOpinion\SignedMailGateway\Config;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class ConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = Config::load();
        self::assertSame(Config::PROVIDER_GATEWAY, $config->provider);
        self::assertTrue($config->fallback);
        self::assertSame([], $config->domains);
        self::assertSame('', $config->defaultFrom);
        self::assertSame('missing_url', $config->problem);
    }

    public function testOptionsAreNormalised(): void
    {
        update_option(Config::OPTION_PROVIDER, 'nonsense');
        update_option(Config::OPTION_FALLBACK, '0');
        update_option(Config::OPTION_DOMAINS, ' Mail.Example.org, ,news.example.org,mail.example.org');
        update_option(Config::OPTION_DEFAULT_FROM, 'not an address');
        $config = Config::load();
        self::assertSame(Config::PROVIDER_GATEWAY, $config->provider);
        self::assertFalse($config->fallback);
        self::assertSame(['mail.example.org', 'news.example.org'], $config->domains);
        self::assertSame('', $config->defaultFrom);
        self::assertContains('default_from_invalid', $config->warnings);
    }

    public function testUrlRules(): void
    {
        self::assertNull(Config::problem('https://gw.example.org', str_repeat('k', 32)));
        self::assertNull(Config::problem('https://gw.example.org/base/', str_repeat('k', 32)));
        self::assertSame('insecure_url', Config::problem('http://gw.example.org', str_repeat('k', 32)));
        self::assertSame('insecure_url', Config::problem('https://user:pw@gw.example.org', str_repeat('k', 32)));
        self::assertSame('insecure_url', Config::problem('https://gw.example.org/?x=1', str_repeat('k', 32)));
        self::assertSame('missing_secret', Config::problem('https://gw.example.org', ''));
        self::assertSame('short_secret', Config::problem('https://gw.example.org', str_repeat('k', 31)));
        $config = new Config('https://gw.example.org/base/', 'x');
        self::assertSame('https://gw.example.org/base/v1/send', $config->endpoint('/v1/send'));
    }

    /** C33: an env file under ABSPATH logs a warning; its values still load. */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testC33EnvFileUnderAbspathWarnsButLoads(): void
    {
        $root = sys_get_temp_dir() . '/smg-webroot-' . bin2hex(random_bytes(4));
        mkdir($root);
        $file = $root . '/gateway.env';
        $env = "# gateway\nexport SIGNED_MAIL_GATEWAY_URL=\"" . self::URL . "\"\n"
            . "SIGNED_MAIL_GATEWAY_SECRET='" . self::SECRET . "'\nOTHER=ignored\n";
        file_put_contents($file, $env);
        define('ABSPATH', $root . '/');
        define('SIGNED_MAIL_GATEWAY_ENV_FILE', $file);
        try {
            $config = Config::load();
            self::assertSame(self::URL, $config->url);
            self::assertSame(self::SECRET, $config->secret);
            self::assertTrue($config->isConfigured());
            self::assertContains('env_file_in_web_root', $config->warnings);

            $this->sentOk();
            self::assertTrue(wp_mail('reader@example.com', 'Hi', 'Body'));
            self::assertStringContainsString('"code":"env_file_in_web_root"', implode("\n", $this->logs));
        } finally {
            unlink($file);
            rmdir($root);
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testEnvFileOutsideWebRootDoesNotWarnAndConstantsWin(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'smg');
        self::assertIsString($file);
        file_put_contents($file, "SIGNED_MAIL_GATEWAY_URL=https://from-file.example.org\nSIGNED_MAIL_GATEWAY_SECRET=" . self::SECRET . "\n");
        define('ABSPATH', __DIR__ . '/');
        define('SIGNED_MAIL_GATEWAY_URL', self::URL);
        define('SIGNED_MAIL_GATEWAY_ENV_FILE', $file);
        try {
            $config = Config::load();
            self::assertSame(self::URL, $config->url);
            self::assertSame(self::SECRET, $config->secret);
            self::assertSame([], $config->warnings);
        } finally {
            unlink($file);
        }
    }

    /** C38: options are per site; constants apply to every site. */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testC38MultisiteOptionsPerSiteConstantsEverywhere(): void
    {
        define('SIGNED_MAIL_GATEWAY_URL', self::URL);
        define('SIGNED_MAIL_GATEWAY_SECRET', self::SECRET);
        \WpStubs::$multisite = true;
        \WpStubs::$sites = [1, 2];
        update_option(Config::OPTION_DOMAINS, 'one.example.org');
        switch_to_blog(2);
        update_option(Config::OPTION_PROVIDER, 'wordpress');
        update_option(Config::OPTION_DOMAINS, 'two.example.org');
        $two = Config::load();
        restore_current_blog();
        $one = Config::load();

        self::assertSame(Config::PROVIDER_WORDPRESS, $two->provider);
        self::assertSame(['two.example.org'], $two->domains);
        self::assertSame(Config::PROVIDER_GATEWAY, $one->provider);
        self::assertSame(['one.example.org'], $one->domains);
        self::assertSame(self::URL, $one->url);
        self::assertSame(self::URL, $two->url);
        self::assertTrue($one->isConfigured() && $two->isConfigured());
    }
}
