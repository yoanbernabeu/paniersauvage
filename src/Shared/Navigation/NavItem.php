<?php

declare(strict_types=1);

namespace App\Shared\Navigation;

final readonly class NavItem
{
    public function __construct(
        public string $route,
        public string $label,
        public string $url,
        /** Lucide icon name, e.g. "lucide:calendar-days". */
        public string $icon,
        public bool $active,
        /** Shown in the bottom bar on mobile; the other entries go in the "Plus" menu. */
        public bool $primary = false,
        /** Short label for the mobile bottom bar. */
        public ?string $shortLabel = null,
    ) {
    }
}
