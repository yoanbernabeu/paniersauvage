<?php

declare(strict_types=1);

namespace App\Delivery\Enum;

enum DeliveryPointType: string
{
    case MeetingPoint = 'meeting_point';
    case TourStop = 'tour_stop';
    case Home = 'home';

    public function label(): string
    {
        return match ($this) {
            self::MeetingPoint => 'Point de rendez-vous',
            self::TourStop => 'Arrêt de tournée',
            self::Home => 'Livraison à domicile',
        };
    }

    /**
     * Lucide icon name (without the `lucide:` prefix).
     */
    public function icon(): string
    {
        return match ($this) {
            self::MeetingPoint => 'map-pin',
            self::TourStop => 'truck',
            self::Home => 'home',
        };
    }
}
