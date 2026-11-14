<?php

declare(strict_types=1);

namespace IndianOpinion\SignedMailGateway\Tests;

use IndianOpinion\SignedMailGateway\SiteHealth;

/** C37: good, recommended and critical, with a five-minute cache. */
final class SiteHealthTest extends TestCase
{
    private function check(int $serverTime): void
    {
        $this->respond(200, ['ok' => true, 'check' => true, 'key_slot' => 'current', 'server_time' => $serverTime, 'max_skew_seconds' => 300]);
    }

    public function testRegistersADirectTest(): void
    {
        $tests = apply_filters('site_status_tests', ['direct' => [], 'async' => []]);
        self::assertIsArray($tests);
        self::assertArrayHasKey(SiteHealth::TEST, $tests['direct']);
    }

    public function testGoodAndCachedForFiveMinutes(): void
    {
        $this->configure();
        $this->check(self::NOW - 10);
        $report = $this->plugin->siteHealth()->run();
        self::assertSame('good', $report['status']);
        self::assertSame(SiteHealth::CACHE_SECONDS, \WpStubs::$transients[1][SiteHealth::TRANSIENT]['expiration']);
        $this->plugin->siteHealth()->run();
        self::assertCount(1, \WpStubs::$requests, 'second run uses the cache');
    }

    public function testRecommendedWhenClocksDifferByMoreThanHalfTheSkew(): void
    {
        $this->configure();
        $this->check(self::NOW - 151);
        self::assertSame('recommended', $this->plugin->siteHealth()->run()['status']);
    }

    public function testCriticalWhenTheCheckFails(): void
    {
        $this->configure();
        $this->respond(401, ['ok' => false, 'error' => 'auth_failed']);
        $report = $this->plugin->siteHealth()->run();
        self::assertSame('critical', $report['status']);
        self::assertStringContainsString('auth_failed', $report['description']);
    }

    public function testCriticalWhenNotConfigured(): void
    {
        $report = $this->plugin->siteHealth()->run();
        self::assertSame('critical', $report['status']);
        self::assertSame([], \WpStubs::$requests);
    }

    public function testGoodWhenProviderIsWordpress(): void
    {
        update_option('signed_mail_gateway_provider', 'wordpress');
        self::assertSame('good', $this->plugin->siteHealth()->run()['status']);
    }

    public function testNothingIsProbedForUsersWhoCannotViewSiteHealth(): void
    {
        $this->configure();
        \WpStubs::$canViewSiteHealth = false;
        $this->plugin->siteHealth()->run();
        self::assertSame([], \WpStubs::$requests);
    }
}
