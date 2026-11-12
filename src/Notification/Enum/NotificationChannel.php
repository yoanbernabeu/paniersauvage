<?php

declare(strict_types=1);

namespace App\Notification\Enum;

/**
 * E-mail only in V1 (SPEC §1.5).
 */
enum NotificationChannel: string
{
    case Email = 'email';
}
