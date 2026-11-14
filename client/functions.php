<?php

declare(strict_types=1);

use IndianOpinion\SignedMailGateway\Plugin;

if (!function_exists('signed_mail_gateway_send_batch')) {
    /**
     * Sends prepared messages (the gateway's `/v1/send` shape) in one signed
     * `/v1/batch` call. Returns one result per input, in input order. Never
     * falls back: the caller decides per message what to do next.
     *
     * @param array<array-key, mixed> $messages At most the gateway's batch limit.
     *
     * @return list<array<string, mixed>>
     */
    function signed_mail_gateway_send_batch(array $messages): array
    {
        return Plugin::instance()->mailer()->sendBatch($messages);
    }
}

if (!function_exists('signed_mail_gateway_last_result')) {
    /**
     * The last decision made in this request: class, code, path, provider
     * id and attempts. Empty before any mail was sent.
     *
     * @return array<string, mixed>
     */
    function signed_mail_gateway_last_result(): array
    {
        return Plugin::instance()->mailer()->lastResult();
    }
}
