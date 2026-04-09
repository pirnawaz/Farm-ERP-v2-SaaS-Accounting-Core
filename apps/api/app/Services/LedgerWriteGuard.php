<?php

namespace App\Services;

use LogicException;

/**
 * Stack-based guard: LedgerEntry::create() must run inside an active posting context opened by an allowlisted class.
 *
 * Posting services wrap work in {@see self::scoped()} or {@see self::enter()} / {@see self::leave()}.
 *
 * @see config/ledger_write_allowlist.php
 */
final class LedgerWriteGuard
{
    private static int $depth = 0;

    public static function assertClassMayWrite(string $className): void
    {
        $allowed = config('ledger_write_allowlist.classes', []);
        if (! in_array($className, $allowed, true)) {
            throw new LogicException(
                "Class {$className} is not allowlisted for ledger writes. Add it to config/ledger_write_allowlist.php if this is an approved posting path."
            );
        }
    }

    /**
     * Begin a guarded section (increments depth). Must pair with {@see leave()}.
     */
    public static function enter(string $writerClass): void
    {
        self::assertClassMayWrite($writerClass);
        self::$depth++;
    }

    public static function leave(): void
    {
        if (self::$depth < 1) {
            throw new LogicException('LedgerWriteGuard::leave() called without matching enter().');
        }
        self::$depth--;
    }

    /**
     * Run a callable inside a guarded section (enter + try/finally leave).
     *
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    public static function scoped(string $writerClass, callable $callback): mixed
    {
        self::enter($writerClass);
        try {
            return $callback();
        } finally {
            self::leave();
        }
    }

    /**
     * Called from LedgerEntry::creating. Ensures create() runs inside an allowlisted posting context.
     */
    public static function assertValidContext(): void
    {
        if (config('ledger_write_allowlist.allow_unguarded_in_tests', false) === true) {
            return;
        }
        if (self::$depth < 1) {
            throw new LogicException(
                'LedgerEntry::create() invoked outside an active LedgerWriteGuard posting context. ' .
                'Use an allowlisted posting service and wrap the operation in LedgerWriteGuard::scoped() (see config/ledger_write_allowlist.php).'
            );
        }
    }
}
