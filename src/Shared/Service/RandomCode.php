<?php

declare(strict_types=1);

namespace App\Shared\Service;

/**
 * Random codes made of unambiguous characters: no 0/O, no 1/I/L.
 */
final class RandomCode
{
    public const string ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    private function __construct()
    {
    }

    public static function generate(int $length): string
    {
        $max = \strlen(self::ALPHABET) - 1;
        $code = '';

        for ($i = 0; $i < $length; ++$i) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }

        return $code;
    }
}
