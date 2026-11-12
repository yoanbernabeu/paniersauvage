<?php

declare(strict_types=1);

namespace App\Ordering\Enum;

/**
 * Where the customer came from before ordering, carried by the `via` parameter of the URL and a
 * hidden field of the order form (never a cookie): the producer directory, the "other producers
 * nearby" block, or a link the producer shared. Unknown (typed address, old link) is stored as null.
 * `subscription` marks the orders generated for a recurring order (App\Recurring): it never comes
 * from the URL (`fromVia()` ignores its value), so a visitor cannot pass an order for a generated one.
 */
enum OrderSource: string
{
    case Directory = 'directory';
    case CrossDiscovery = 'cross_discovery';
    case SharedLink = 'shared_link';
    case Subscription = 'subscription';

    /** Values of the `via` parameter. */
    public const string VIA_DIRECTORY = 'recherche';
    public const string VIA_CROSS_DISCOVERY = 'croise';
    public const string VIA_SHARED_LINK = 'lien';
    /** Never read back from a URL. */
    public const string VIA_SUBSCRIPTION = 'abonnement';

    public static function fromVia(mixed $via): ?self
    {
        return match ($via) {
            self::VIA_DIRECTORY => self::Directory,
            self::VIA_CROSS_DISCOVERY => self::CrossDiscovery,
            self::VIA_SHARED_LINK => self::SharedLink,
            default => null,
        };
    }

    public function via(): string
    {
        return match ($this) {
            self::Directory => self::VIA_DIRECTORY,
            self::CrossDiscovery => self::VIA_CROSS_DISCOVERY,
            self::SharedLink => self::VIA_SHARED_LINK,
            self::Subscription => self::VIA_SUBSCRIPTION,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Directory => 'Annuaire des producteurs',
            self::CrossDiscovery => 'Autre producteur à proximité',
            self::SharedLink => 'Lien partagé par le producteur',
            self::Subscription => 'Abonnement (commande récurrente)',
        };
    }
}
