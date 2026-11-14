<?php

declare(strict_types=1);

namespace IndianOpinion\SignedMailGateway;

/**
 * The outcome of one message, or of one call such as a check.
 *
 * Holds no address, subject or body: only codes, counts and ids.
 */
final class Result
{
    /**
     * @param list<string>         $droppedHeaders Header names the gateway dropped.
     * @param array<string, mixed> $reply          The decoded gateway reply, for check calls.
     */
    public function __construct(
        public readonly string $class,
        public readonly string $code,
        public readonly ?int $status = null,
        public readonly string $id = '',
        public readonly string $path = Outcome::PATH_GATEWAY,
        public readonly int $attempts = 1,
        public readonly string $providerCode = '',
        public readonly string $domain = '',
        public readonly array $droppedHeaders = [],
        public readonly ?int $serverTime = null,
        public readonly array $reply = [],
    ) {
    }

    public function isSent(): bool
    {
        return $this->class === Outcome::SENT;
    }

    public function withPath(string $path, int $attempts): self
    {
        return new self(
            $this->class,
            $this->code,
            $this->status,
            $this->id,
            $path,
            $attempts,
            $this->providerCode,
            $this->domain,
            $this->droppedHeaders,
            $this->serverTime,
            $this->reply,
        );
    }

    public function withClass(string $class): self
    {
        return new self(
            $class,
            $this->code,
            $this->status,
            $this->id,
            $this->path,
            $this->attempts,
            $this->providerCode,
            $this->domain,
            $this->droppedHeaders,
            $this->serverTime,
            $this->reply,
        );
    }

    /**
     * The shape returned by the global functions.
     *
     * @return array{class: string, code: string, status: int|null, id: string, path: string, attempts: int,
     *     provider_code: string, domain: string, dropped_headers: list<string>}
     */
    public function toArray(): array
    {
        return [
            'class' => $this->class,
            'code' => $this->code,
            'status' => $this->status,
            'id' => $this->id,
            'path' => $this->path,
            'attempts' => $this->attempts,
            'provider_code' => $this->providerCode,
            'domain' => $this->domain,
            'dropped_headers' => $this->droppedHeaders,
        ];
    }
}
