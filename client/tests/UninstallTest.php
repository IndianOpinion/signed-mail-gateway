<?php

declare(strict_types=1);

namespace IndianOpinion\SignedMailGateway\Tests;

use IndianOpinion\SignedMailGateway\Config;
use IndianOpinion\SignedMailGateway\SiteHealth;
use IndianOpinion\SignedMailGateway\Uninstaller;

/** C34: uninstall deletes the four options and the transient, on every site. */
final class UninstallTest extends TestCase
{
    public function testC34DeletesOptionsAndTransientOnEverySite(): void
    {
        \WpStubs::$multisite = true;
        \WpStubs::$sites = [1, 2];
        foreach ([1, 2] as $blog) {
            switch_to_blog($blog);
            foreach (Config::OPTIONS as $option) {
                update_option($option, 'x');
            }
            update_option('another_plugin_smtp_host', 'keep');
            set_transient(SiteHealth::TRANSIENT, ['ok' => true], 300);
            restore_current_blog();
        }

        Uninstaller::run();

        foreach ([1, 2] as $blog) {
            self::assertSame(['another_plugin_smtp_host' => 'keep'], \WpStubs::$options[$blog]);
            self::assertSame([], \WpStubs::$transients[$blog]);
        }
        self::assertSame(1, \WpStubs::$blog);
    }

    public function testSingleSite(): void
    {
        foreach (Config::OPTIONS as $option) {
            update_option($option, 'x');
        }
        Uninstaller::run();
        self::assertSame([], \WpStubs::$options[1]);
    }
}
