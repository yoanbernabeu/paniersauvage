<?php

declare(strict_types=1);

namespace App\Shared\Map;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Tile server of the maps (producer directory, shop pages), set by MAP_TILE_URL and
 * MAP_TILE_ATTRIBUTION. The Plan IGN of the Géoplateforme by default (WMTS, free, keyless, no quota,
 * Licence Ouverte Etalab); any provider with an HTTPS URL works the same way (docs/DEPLOY.md).
 *
 * The Content-Security-Policy allows the images of this one origin (`origin()`), nothing wider.
 * An empty, non-HTTPS or malformed URL, or an empty attribution, turns the maps off: the pages
 * keep their lists.
 */
final readonly class MapTiles
{
    /** Leaflet's placeholder for the subdomains "a", "b", "c" of some providers. */
    private const string SUBDOMAIN_PLACEHOLDER = '{s}.';

    private ?string $origin;

    public function __construct(
        #[Autowire(env: 'MAP_TILE_URL')]
        private string $url,
        #[Autowire(env: 'MAP_TILE_ATTRIBUTION')]
        private string $attribution,
        #[Autowire(env: 'MAP_TILE_ATTRIBUTION_URL')]
        private string $attributionUrl = '',
    ) {
        $this->origin = $this->originOf(trim($url));
    }

    public function isEnabled(): bool
    {
        return null !== $this->origin && '' !== trim($this->attribution);
    }

    /**
     * Source of the CSP directive img-src: "https://data.geopf.fr", or
     * "https://*.tile.example.org" for a URL with Leaflet's {s} subdomains. Null when disabled.
     */
    public function origin(): ?string
    {
        return $this->isEnabled() ? $this->origin : null;
    }

    /**
     * What the map script needs, or null when maps are off.
     *
     * @return array{url: string, attribution: string, attributionUrl: ?string}|null
     */
    public function config(): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $attributionUrl = trim($this->attributionUrl);

        return [
            'url' => trim($this->url),
            'attribution' => trim($this->attribution),
            'attributionUrl' => 1 === preg_match('#^https://[^\s"<>]+$#i', $attributionUrl) ? $attributionUrl : null,
        ];
    }

    private function originOf(string $url): ?string
    {
        if (!str_starts_with(strtolower($url), 'https://')) {
            return null;
        }

        $authority = substr($url, \strlen('https://'));
        $end = strcspn($authority, '/?#');
        $authority = strtolower(substr($authority, 0, $end));

        $wildcard = str_starts_with($authority, self::SUBDOMAIN_PLACEHOLDER);
        if ($wildcard) {
            $authority = substr($authority, \strlen(self::SUBDOMAIN_PLACEHOLDER));
        }

        // A host name of at least two labels, an optional port; no credentials, no placeholder.
        if (1 !== preg_match('/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?::\d{1,5})?$/', $authority)) {
            return null;
        }

        return 'https://'.($wildcard ? '*.' : '').$authority;
    }
}
