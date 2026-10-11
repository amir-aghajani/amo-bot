<?php

declare(strict_types=1);

namespace App\Core\Security;

/**
 * Random codes a person reads off one screen and types into another (host keys, two-factor recovery codes): capitals
 * and digits without look-alikes (0/O, 1/I/L), about five random bits a character.
 */
final class ReadableCode
{
    public const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    /** `$length` characters of ALPHABET, each drawn at random. */
    public static function make(int $length): string
    {
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $code;
    }
}
