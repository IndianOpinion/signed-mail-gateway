# signed-mail-gateway

A Cloudflare Worker that sends mail through Cloudflare Email Sending only for requests signed with HMAC-SHA256 over timestamp, nonce and body, refusing replays and clock skew. Comes with a WordPress pre_wp_mail client and a provider switch back to SMTP.
