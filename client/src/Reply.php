<?php

declare(strict_types=1);

namespace IndianOpinion\SignedMailGateway;

/** One raw HTTP exchange with the gateway, before classification. */
final class Reply
{
    /**
     * @param array<mixed>|null $json The reply decoded as a JSON object, or null.
     */
    public function __construct(
        public readonly ?int $status,
        public readonly ?array $json,
        public readonly string $transportError = '',
    ) {
    }

    public function failedInTransport(): bool
    {
        return $this->status === null;
    }
}
