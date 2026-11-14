<?php

/**
 * Must-use plugin loader.
 *
 * Copy the client/ directory to wp-content/mu-plugins/signed-mail-gateway/
 * and this file to wp-content/mu-plugins/signed-mail-gateway-loader.php.
 * WordPress loads only PHP files at the top of mu-plugins, so this one line
 * is what makes it run.
 */

declare(strict_types=1);

require __DIR__ . '/signed-mail-gateway/signed-mail-gateway.php';
