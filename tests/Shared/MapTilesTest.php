<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Shared\Map\MapTiles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MapTilesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function urls(): iterable
    {
        yield 'Plan IGN (WMTS, query string)' => ['https://data.geopf.fr/wmts?SERVICE=WMTS&REQUEST=GetTile&VERSION=1.0.0&LAYER=GEOGRAPHICALGRIDSYSTEMS.PLANIGNV2&STYLE=normal&TILEMATRIXSET=PM_0_19&TILEMATRIX={z}&TILEROW={y}&TILECOL={x}&FORMAT=image/png', 'https://data.geopf.fr'];
        yield 'OpenStreetMap' => ['https://tile.openstreetmap.org/{z}/{x}/{y}.png', 'https://tile.openstreetmap.org'];
        yield 'subdomains' => ['https://{s}.tile.example.org/{z}/{x}/{y}.png', 'https://*.tile.example.org'];
        yield 'port, query and case' => ['HTTPS://Tiles.Example.org:8443/{z}/{x}/{y}.png?key=abc', 'https://tiles.example.org:8443'];
        yield 'not HTTPS' => ['http://tile.openstreetmap.org/{z}/{x}/{y}.png', null];
        yield 'protocol-relative' => ['//tile.openstreetmap.org/{z}/{x}/{y}.png', null];
        yield 'empty' => ['', null];
        yield 'credentials' => ['https://user:secret@tiles.example.org/{z}/{x}/{y}.png', null];
        yield 'placeholder elsewhere in the host' => ['https://tiles.{s}.example.org/{z}/{x}/{y}.png', null];
        yield 'a bare wildcard' => ['https://*/{z}/{x}/{y}.png', null];
        yield 'a single label' => ['https://localhost/{z}/{x}/{y}.png', null];
        yield 'a CSP keyword smuggled in' => ["https://tiles.example.org 'unsafe-inline'/{z}.png", null];
        yield 'a directive smuggled in' => ['https://tiles.example.org;script-src *', null];
    }

    #[DataProvider('urls')]
    public function testTheOriginAllowedByThePolicyIsTheHostOfTheTiles(string $url, ?string $origin): void
    {
        $tiles = new MapTiles($url, '© contributeurs OpenStreetMap');

        self::assertSame($origin, $tiles->origin());
        self::assertSame(null !== $origin, $tiles->isEnabled());
        self::assertSame(null !== $origin, null !== $tiles->config());
    }

    public function testAMapNeedsAnAttribution(): void
    {
        $tiles = new MapTiles('https://tile.openstreetmap.org/{z}/{x}/{y}.png', ' ');

        self::assertFalse($tiles->isEnabled());
        self::assertNull($tiles->origin());
        self::assertNull($tiles->config());
    }

    public function testOnlyAnHttpsAttributionLinkIsKept(): void
    {
        $url = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';

        self::assertSame('https://www.openstreetmap.org/copyright', new MapTiles($url, '©', 'https://www.openstreetmap.org/copyright')->config()['attributionUrl'] ?? null);
        self::assertNull(new MapTiles($url, '©', 'javascript:alert(1)')->config()['attributionUrl'] ?? null);
        self::assertNull(new MapTiles($url, '©', 'https://x.org/"><script>')->config()['attributionUrl'] ?? null);
    }
}
