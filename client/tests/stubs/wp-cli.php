<?php

/**
 * A stand-in for the WP_CLI class: records output, and error() throws the
 * exit code instead of exiting.
 */

declare(strict_types=1);

// phpcs:disable PSR1.Files.SideEffects, PSR1.Classes.ClassDeclaration, Squiz.Classes.ValidClassName

final class WpCliExit extends RuntimeException
{
}

final class WP_CLI
{
    /** @var list<string> */
    public static array $out = [];
    /** @var array<string, mixed> */
    public static array $commands = [];

    public static function reset(): void
    {
        self::$out = [];
        self::$commands = [];
    }

    public static function line(string $message = ''): void
    {
        self::$out[] = $message;
    }

    public static function success(string $message): void
    {
        self::$out[] = 'Success: ' . $message;
    }

    public static function warning(string $message): void
    {
        self::$out[] = 'Warning: ' . $message;
    }

    /**
     * @return never
     */
    public static function error(string $message): void
    {
        self::$out[] = 'Error: ' . $message;

        throw new WpCliExit($message, 1);
    }

    /**
     * @param mixed $callable
     */
    public static function add_command(string $name, $callable): bool
    {
        self::$commands[$name] = $callable;

        return true;
    }
}
