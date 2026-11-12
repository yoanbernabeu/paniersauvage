<?php

declare(strict_types=1);

namespace App\Notification\Enum;

enum NotificationStatus: string
{
    case Sent = 'sent';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Sent => 'Envoyé',
            self::Failed => 'Échec',
            self::Skipped => 'Non envoyé',
        };
    }
}
