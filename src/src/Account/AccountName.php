<?php

declare(strict_types=1);

namespace App\Account;

/**
 * A player's name: 2–24 characters after trimming (inner runs of spaces collapse), letters of any script,
 * digits, spaces and - _ . '.
 */
final class AccountName
{
    public const int MIN = 2;
    public const int MAX = 24;

    /**
     * @throws \InvalidArgumentException when the name does not fit
     */
    public static function normalize(string $name): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        $length = mb_strlen($name);
        if ($length < self::MIN || $length > self::MAX) {
            throw new \InvalidArgumentException(sprintf('A name has %d to %d characters', self::MIN, self::MAX));
        }
        if (preg_match("/^[\\p{L}\\p{N} \\-_.']+$/u", $name) !== 1) {
            throw new \InvalidArgumentException('A name has letters, digits, spaces and - _ . \' only');
        }

        return $name;
    }
}
