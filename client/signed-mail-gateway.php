<?php

/**
 * Plugin Name:       Signed Mail Gateway
 * Plugin URI:        https://github.com/IndianOpinion/signed-mail-gateway
 * Description:       Sends wp_mail() through an HMAC-signed Cloudflare Worker, with a safe one-time fallback to the normal mail path.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Indian Opinion
 * Author URI:        https://indianopinion.org
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       signed-mail-gateway
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/bootstrap.php';

\IndianOpinion\SignedMailGateway\Plugin::boot();
