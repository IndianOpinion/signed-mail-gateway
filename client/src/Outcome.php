<?php

declare(strict_types=1);

namespace IndianOpinion\SignedMailGateway;

/** The classes every gateway attempt ends in (PRD case 77). */
final class Outcome
{
    /** 200 and ok: the provider took the message. */
    public const SENT = 'sent';
    /** The recipient is known to bounce or complain. Never fall back. */
    public const SUPPRESSED = 'suppressed';
    /** The message or its recipients are wrong. Never fall back. */
    public const REJECTED = 'rejected';
    /** Certainly not sent, try later. */
    public const RETRY = 'retry';
    /** The provider's daily limit is reached. */
    public const QUOTA = 'quota';
    /** Certainly not sent, the gateway cannot take it. */
    public const UNAVAILABLE = 'unavailable';
    /** The message may have been sent. Never fall back. */
    public const UNKNOWN = 'unknown';

    public const ALL = [
        self::SENT,
        self::SUPPRESSED,
        self::REJECTED,
        self::RETRY,
        self::QUOTA,
        self::UNAVAILABLE,
        self::UNKNOWN,
    ];

    /** Classes after which the core path is tried once, by default. */
    public const FALLBACK_DEFAULT = [self::UNAVAILABLE, self::QUOTA, self::RETRY];

    public const PATH_GATEWAY = 'gateway';
    public const PATH_WORDPRESS = 'wordpress';
    public const PATH_NONE = 'none';

    private function __construct()
    {
    }
}
