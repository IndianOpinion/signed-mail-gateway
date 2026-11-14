<?php

/**
 * Deletes the plugin's four options and its Site Health transient.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/bootstrap.php';

\IndianOpinion\SignedMailGateway\Uninstaller::run();
