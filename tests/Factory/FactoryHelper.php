<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use Zenstruck\Foundry\Factory;

/**
 * Lets a factory derive a default (e.g. a product of the sale's shop) from another attribute.
 */
trait FactoryHelper
{
    /**
     * Turns a nested factory into its object inside a beforeInstantiate hook.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private static function resolve(mixed $value, string $class): object
    {
        if ($value instanceof Factory) {
            $value = $value->create();
        }

        if (!$value instanceof $class) {
            throw new \InvalidArgumentException(\sprintf('Expected an instance of "%s", got "%s".', $class, get_debug_type($value)));
        }

        return $value;
    }
}
