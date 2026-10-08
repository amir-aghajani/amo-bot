<?php

declare(strict_types=1);

namespace App\Core\Security;

/**
 * Random codes a person reads off one screen and types into another — the installer's and a lost login's key off a host's
 * File Manager (HostKey), a website customer's two-factor recovery codes off the sheet they kept them on: capital letters
 * and digits without the ones that look alike (0/O, 1/I/L), each character some five random bits.
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
