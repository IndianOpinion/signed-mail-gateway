<?php

/**
 * A newsletter-style batch from inside WordPress (for example with
 * `wp eval-file examples/send-batch.php`). The batch API never falls back by
 * itself: the caller looks at each result and decides.
 */

declare(strict_types=1);

$messages = [];
foreach (['one@example.com', 'two@example.com'] as $address) {
    $messages[] = [
        'from' => ['email' => 'news@mail.example.org', 'name' => 'Example News'],
        'to' => [$address],
        'subject' => 'This week',
        'text' => "This week's issue.",
        'headers' => [
            'List-Unsubscribe' => '<https://example.org/unsubscribe?u=123>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ],
    ];
}

foreach (signed_mail_gateway_send_batch($messages) as $i => $result) {
    // retry and quota: try again later. unavailable: maybe send by SMTP.
    // rejected and suppressed: drop. unknown: do not resend, it may have gone.
    printf("%d: %s (%s) %s\n", $i, $result['class'], $result['code'], $result['id']);
}
