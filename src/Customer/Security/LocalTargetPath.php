<?php

declare(strict_types=1);

namespace App\Customer\Security;

/**
 * Keeps a `_target_path` only when it is a path of this site ("/mon-compte"), never an absolute or
 * protocol-relative URL that would turn a sign-in link into an open redirect.
 */
final class LocalTargetPath
{
    public static function sanitize(mixed $path): ?string
    {
        if (!\is_string($path) || !str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '\\')) {
            return null;
        }

        if (1 === preg_match('/[\x00-\x1F\x7F]/', $path)) {
            return null;
        }

        return $path;
    }
}
