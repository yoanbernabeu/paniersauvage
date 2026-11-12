<?php

declare(strict_types=1);

namespace App\Instance;

/**
 * An INSTANCE_* environment variable holds a value the application cannot use: the message names
 * the variable and what it accepts (docs/INSTANCE.md).
 */
final class InvalidInstanceSettingException extends \InvalidArgumentException
{
    public static function for(string $variable, string $expected, string $value): self
    {
        return new self(\sprintf('%s: %s (value: "%s"). See docs/INSTANCE.md.', $variable, $expected, $value));
    }
}
