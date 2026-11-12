<?php

declare(strict_types=1);

namespace App\Tests\Instance;

use App\Delivery\Entity\DeliveryPoint;
use App\Delivery\Enum\DeliveryPointType;
use App\Shop\Entity\Shop;
use App\Tests\Delivery\Support\FakeBan;
use App\Tests\Delivery\Support\FakeBanTrait;
use App\Tests\Factory\ShopFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The INSEE code of a point (what a territory is made of) comes from the geocoder (`citycode` of
 * the BAN), and goes with the address it was found for.
 */
final class InseeCodeTest extends KernelTestCase
{
    use Factories;
    use FakeBanTrait;

    public function testTheGeocoderGivesTheInseeCodeOfThePoint(): void
    {
        FakeBan::knows('Place des Clercs 26000 Valence', 44.9330, 4.8920, city: 'Valence', postcode: '26000', citycode: '26362');
        FakeBan::knows('Valence', 44.9334, 4.8924, city: 'Valence', postcode: '26000', citycode: '26362');
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $shop = ShopFactory::createOne();

        $point = new DeliveryPoint($shop, 'Place des Clercs')->setAddress("Place des Clercs\n26000 Valence");
        $entityManager->persist($point);
        $entityManager->flush();
        self::assertSame('26362', $point->getInseeCode());

        // A new address: the code of the old one is forgotten until the new one is found.
        $point->setAddress("Place de la Mairie\n07500 Guilherand-Granges");
        self::assertNull($point->getInseeCode());
        $entityManager->flush();
        self::assertNull($point->getInseeCode(), 'Not found by the geocoder.');
        self::assertSame('07500', $point->getPostcode());

        // A home delivery zone: the code of its town.
        $home = new DeliveryPoint($shop, 'À domicile', DeliveryPointType::Home)->setZoneTown('Valence')->setZoneRadiusKm(10);
        $entityManager->persist($home);
        $entityManager->flush();
        self::assertSame('26362', $home->getInseeCode());

        $entityManager->clear();
        $stored = $entityManager->getConnection()->fetchFirstColumn('SELECT insee_code FROM delivery_point ORDER BY id');
        self::assertSame([null, '26362'], $stored);
    }

    public function testAGeocoderWithoutCodeLeavesItEmpty(): void
    {
        FakeBan::knows('Place des Clercs 26000 Valence', 44.9330, 4.8920, city: 'Valence', postcode: '26000');
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $point = new DeliveryPoint(ShopFactory::createOne(), 'Place des Clercs')->setAddress("Place des Clercs\n26000 Valence");
        $entityManager->persist($point);
        $entityManager->flush();

        self::assertNull($point->getInseeCode());
        self::assertSame('valence-26', $point->getCommuneSlug());
    }

    public function testOnlyRealInseeCodesAreKept(): void
    {
        $point = new DeliveryPoint(new Shop('Fournil', 'fournil'), 'Place');

        self::assertSame('2A004', $point->setTown('Ajaccio', '20000', ' 2a004 ')->getInseeCode());
        self::assertSame('26362', $point->setTown('Valence', '26000', '26362')->getInseeCode());
        foreach (['', '2636', '263620', '2C004', 'valence', '26 362'] as $code) {
            self::assertNull($point->setTown('Valence', '26000', $code)->getInseeCode(), $code);
        }
        self::assertNull($point->setTown('Valence', '26000')->getInseeCode());
    }
}
