<?php

declare(strict_types=1);

namespace IndianOpinion\SignedMailGateway;

/**
 * Removes what the plugin created: four options and the Site Health
 * transient, on every site of a network. Nothing else.
 */
final class Uninstaller
{
    public static function run(): void
    {
        if (function_exists('is_multisite') && is_multisite() && function_exists('get_sites')) {
            $ids = get_sites(['fields' => 'ids', 'number' => 0]);
            foreach ($ids as $id) {
                switch_to_blog((int) $id);
                self::cleanSite();
                restore_current_blog();
            }

            return;
        }
        self::cleanSite();
    }

    private static function cleanSite(): void
    {
        foreach (Config::OPTIONS as $option) {
            delete_option($option);
        }
        delete_transient(SiteHealth::TRANSIENT);
    }
}
