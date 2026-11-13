<?php

declare(strict_types=1);

namespace IndianOpinion\SignedMailGateway;

/** A `wp_mail()` call turned into a gateway payload, plus what the client needs to know about it. */
final class BuiltMessage
{
    /**
     * @param array<string, mixed> $payload  The `/v1/send` message.
     * @param array<string, mixed> $mailData Core's `$mail_data` shape, for the mail events.
     */
    public function __construct(
        public readonly array $payload,
        public readonly array $mailData,
        public readonly bool $hasAttachments,
        public readonly int $recipients,
        public readonly string $firstRecipient,
        public readonly int $droppedLocally,
    ) {
    }
}
