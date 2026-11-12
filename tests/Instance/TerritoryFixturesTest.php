<?php

declare(strict_types=1);

namespace App\Tests\Instance;

use App\DataFixtures\ValenceFixtures;
use App\Tests\Delivery\Support\FakeBanTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The demo data on a territorial instance limited to the towns of Valence Romans Agglo (by INSEE
 * code, as docs/INSTANCE.md suggests): Montélimar, Livron, Tain and Nyons are outside.
 */
final class TerritoryFixturesTest extends WebTestCase
{
    use FakeBanTrait;
    use InstanceTrait;

    /** Valence Romans Agglo towns of the demo data. */
    private const string AGGLO = '26362 26064 26197 26004 26037 26124 26252 26058 26281 26057 26313 26084';

    public function testTheDemoDataOnTheAggloInstance(): void
    {
        $this->useTerritorialInstance(['INSTANCE_TERRITORY' => self::AGGLO, 'INSTANCE_TERRITORY_CENTER' => '44.9334,4.8924', 'INSTANCE_TERRITORY_RADIUS_KM' => '50']);
        $client = self::createClient();
        $client->getContainer()->get(ValenceFixtures::class)->load($client->getContainer()->get(EntityManagerInterface::class));

        $crawler = $client->request('GET', '/trouver-un-producteur?lat=44.933&lng=4.892&rayon=50');
        $found = $crawler->filter('[data-result]')->each(static fn (Crawler $node): string => (string) $node->attr('data-result'));
        self::assertNotContains('nougaterie-du-jabron', $found, 'Montélimar is outside the agglo.');
        self::assertContains('fournil-de-romans', $found);
        self::assertContains('potager-de-l-eperviere', $found);

        $crawler = $client->request('GET', '/trouver-un-producteur');
        $towns = $crawler->filter('[data-communes] a')->each(static fn (Crawler $node): string => trim((string) preg_replace('/\s+\d+$/', '', trim($node->text()))));
        self::assertNotContains('Montélimar (26)', $towns);
        self::assertContains('Valence (26)', $towns);
        self::assertContains('Romans-sur-Isère (26)', $towns);
        self::assertGreaterThan(5, $crawler->filter('[data-territory-results] [data-result]')->count());
        self::assertSame('riviere', $crawler->filter('html')->attr('data-accent'));
    }
}
