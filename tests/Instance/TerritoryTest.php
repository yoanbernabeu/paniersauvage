<?php

declare(strict_types=1);

namespace App\Tests\Instance;

use App\Delivery\Entity\DeliveryPoint;
use App\Instance\InvalidInstanceSettingException;
use App\Instance\Territory;
use App\Shop\Entity\Shop;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The territory of an instance: its parsing, its rule (`contains()`) and the SQL translation of the
 * rule (`pointSql()`), compared case by case on PostgreSQL.
 */
final class TerritoryTest extends KernelTestCase
{
    /** INSEE code, postcode: Valence, Privas (07), Ajaccio (2A), Bastia (2B), Saint-Denis (974), unknown. */
    private const array TOWNS = [
        'valence' => ['26362', '26000'],
        'romans' => ['26281', '26100'],
        'privas' => ['07186', '07000'],
        'ajaccio' => ['2A004', '20000'],
        'bastia' => ['2B033', '20200'],
        'saint-denis' => ['97411', '97400'],
        'valence, not geocoded' => [null, '26000'],
        'privas, not geocoded' => [null, '07000'],
        'ajaccio, not geocoded' => [null, '20000'],
        'saint-denis, not geocoded' => [null, '97400'],
        'no town' => [null, null],
    ];

    public function testEverywhereRestrictsNothing(): void
    {
        $territory = Territory::everywhere();

        self::assertFalse($territory->isRestricted());
        self::assertFalse($territory->hasCenter());
        self::assertSame('TRUE', $territory->pointSql('p'));
        self::assertTrue($territory->contains(null, null));
        self::assertEquals($territory, Territory::fromSettings(''));
    }

    public function testCodesAreSortedIntoDepartmentsAndTowns(): void
    {
        $territory = Territory::fromSettings(" 26 ;07,2a 26362\n26281 974 26 ", 'Drôme', ' 44.9334 , 4.8924 ', '50');

        self::assertSame(['26', '07', '2A', '974'], $territory->departments);
        self::assertSame(['26362', '26281'], $territory->communes);
        self::assertSame('Drôme', $territory->name);
        self::assertTrue($territory->isRestricted());
        self::assertTrue($territory->hasCenter());
        self::assertSame(44.9334, $territory->centerLatitude);
        self::assertSame(4.8924, $territory->centerLongitude);
        self::assertSame(50, $territory->radiusKm);
        self::assertTrue(Territory::fromSettings('26362')->isRestricted());
        self::assertSame(-21.1151, Territory::fromSettings('', '', '-21.1151,55.5364')->centerLatitude);
        self::assertSame([44.9334, 4.8924], $territory->center());
        self::assertSame([90.0, -180.0], Territory::fromSettings('', '', '90,-180')->center(), 'The bounds are allowed.');
        self::assertSame([-90.0, 180.0], Territory::fromSettings('', '', '-90 , 180')->center());
        self::assertNull(Territory::fromSettings('26')->center());
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function invalidCodes(): iterable
    {
        yield 'a name' => ['Drôme', 'INSTANCE_TERRITORY'];
        yield 'a postcode-like code of 4 digits' => ['2636', 'INSTANCE_TERRITORY'];
        yield 'six digits' => ['263620', 'INSTANCE_TERRITORY'];
        yield 'a quote' => ["26'", 'INSTANCE_TERRITORY'];
        yield 'a department of one digit' => ['7', 'INSTANCE_TERRITORY'];
    }

    #[DataProvider('invalidCodes')]
    public function testAnUnknownCodeIsRefused(string $codes, string $variable): void
    {
        $this->expectException(InvalidInstanceSettingException::class);
        $this->expectExceptionMessageIsOrContains($variable.': a list of department codes');

        Territory::fromSettings($codes);
    }

    public function testTheCentreAndTheRadiusAreChecked(): void
    {
        foreach (['44.9', '44.9,4.8,1', '91,4.8', '44.9,181', 'nord,est'] as $center) {
            try {
                Territory::fromSettings('26', '', $center);
                self::fail($center);
            } catch (InvalidInstanceSettingException $exception) {
                self::assertStringStartsWith('INSTANCE_TERRITORY_CENTER:', $exception->getMessage());
            }
        }

        foreach (['0', '15', '30 km', '-10', '100'] as $radius) {
            try {
                Territory::fromSettings('26', '', '', $radius);
                self::fail($radius);
            } catch (InvalidInstanceSettingException $exception) {
                self::assertStringStartsWith('INSTANCE_TERRITORY_RADIUS_KM:', $exception->getMessage());
            }
        }

        self::assertSame(30, Territory::fromSettings('26', '', '', ' ')->radiusKm);
        self::assertSame(10, Territory::fromSettings('26', '', '', '10')->radiusKm);
    }

    /**
     * @return iterable<string, array{0: string, 1: list<string>}>
     */
    public static function territories(): iterable
    {
        yield 'Drôme' => ['26', ['valence', 'romans', 'valence, not geocoded']];
        yield 'Drôme and Ardèche' => ['26 07', ['valence', 'romans', 'privas', 'valence, not geocoded', 'privas, not geocoded']];
        yield 'two towns' => ['26362 07186', ['valence', 'privas']];
        yield 'a town and a department' => ['26281 07', ['romans', 'privas', 'privas, not geocoded']];
        yield 'Southern Corsica' => ['2A', ['ajaccio', 'ajaccio, not geocoded']];
        yield 'Upper Corsica' => ['2B', ['bastia', 'ajaccio, not geocoded']];
        yield 'a Corsican town' => ['2B033', ['bastia']];
        yield 'La Réunion' => ['974', ['saint-denis', 'saint-denis, not geocoded']];
    }

    /**
     * @param list<string> $inside
     */
    #[DataProvider('territories')]
    public function testTheRuleAndItsSqlAgree(string $codes, array $inside): void
    {
        $territory = Territory::fromSettings($codes);

        $contained = array_keys(array_filter(self::TOWNS, static fn (array $town): bool => $territory->contains($town[0], $town[1])));
        self::assertSame($inside, $contained, 'PHP rule');

        $rows = [];
        foreach (array_keys(self::TOWNS) as $index => $label) {
            [$insee, $postcode] = self::TOWNS[$label];
            $rows[] = \sprintf('(%d, CAST(%s AS VARCHAR(5)), CAST(%s AS VARCHAR(10)))', $index, null === $insee ? 'NULL' : "'".$insee."'", null === $postcode ? 'NULL' : "'".$postcode."'");
        }

        self::bootKernel();
        $ids = self::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchFirstColumn(
            \sprintf('SELECT tp.id FROM (VALUES %s) AS tp(id, insee_code, postcode) WHERE %s ORDER BY tp.id', implode(', ', $rows), $territory->pointSql('tp')),
        );
        $labels = array_keys(self::TOWNS);
        self::assertSame($inside, array_map(static fn (mixed $id): string => $labels[is_numeric($id) ? (int) $id : -1], $ids), 'SQL translation');
    }

    public function testAPointIsJudgedByItsTown(): void
    {
        $territory = Territory::fromSettings('26362');
        $point = new DeliveryPoint(new Shop('Fournil', 'fournil'), 'Place');

        $point->setTown('Valence', '26000');
        self::assertFalse($territory->containsPoint($point), 'Without INSEE code, a town list cannot match.');

        $point->setTown('Valence', '26000', '26362');
        self::assertTrue($territory->containsPoint($point));
        self::assertTrue(Territory::fromSettings('26')->containsPoint($point));
        self::assertFalse(Territory::fromSettings('07')->containsPoint($point));
    }

    public function testDepartmentsOfCodes(): void
    {
        self::assertSame('26', Territory::departmentOf('26362'));
        self::assertSame('2A', Territory::departmentOf('2A004'));
        self::assertSame('974', Territory::departmentOf('97411'));
        self::assertSame('988', Territory::departmentOf('98818'));
        self::assertSame('20', Territory::departmentOf('20000'));
    }
}
