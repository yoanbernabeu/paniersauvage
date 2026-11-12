<?php

declare(strict_types=1);

namespace App\Tests\Sale;

use App\Sale\Entity\SaleOffer;
use App\Tests\Factory\SaleOfferFactory;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

final class SaleOfferTest extends KernelTestCase
{
    use Factories;

    public function testRemainingOfALimitedOffer(): void
    {
        $offer = SaleOfferFactory::createOne(['maxQty' => 12000]);

        self::assertTrue($offer->isLimited());
        self::assertSame(12000, $offer->remaining());

        $offer->reserve(5000);
        self::assertSame(5000, $offer->getReservedQty());
        self::assertSame(7000, $offer->remaining());
        self::assertTrue($offer->canReserve(7000));
        self::assertFalse($offer->canReserve(7001));
        self::assertFalse($offer->isSoldOut());

        $offer->reserve(7000);
        self::assertSame(0, $offer->remaining());
        self::assertTrue($offer->isSoldOut());

        $offer->release(2000);
        self::assertSame(2000, $offer->remaining());
    }

    public function testAnUnlimitedOfferNeverRunsOut(): void
    {
        $offer = SaleOfferFactory::createOne();

        self::assertFalse($offer->isLimited());
        self::assertNull($offer->remaining());
        self::assertTrue($offer->canReserve(10_000));
        self::assertFalse($offer->isSoldOut());

        $offer->reserve(500000);
        self::assertSame(500000, $offer->getReservedQty());
        self::assertNull($offer->remaining());
    }

    public function testReservingMoreThanAvailableIsRefused(): void
    {
        $offer = SaleOfferFactory::createOne(['maxQty' => 3000, 'reservedQty' => 2000]);

        $this->expectException(\DomainException::class);
        $offer->reserve(2000);
    }

    public function testReleaseNeverGoesBelowZero(): void
    {
        $offer = SaleOfferFactory::createOne(['maxQty' => 3000, 'reservedQty' => 1000]);

        $offer->release(5000);

        self::assertSame(0, $offer->getReservedQty());
    }

    public function testTheDatabaseRefusesOverselling(): void
    {
        $offer = SaleOfferFactory::createOne(['maxQty' => 3000, 'reservedQty' => 3000]);
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();

        // Bypass the entity on purpose: the CHECK constraint is the last line of defence.
        $this->expectException(DriverException::class);
        $this->expectExceptionMessageMatches('/chk_sale_offer_reserved_qty/');
        $connection->executeStatement('UPDATE sale_offer SET reserved_qty = 4000 WHERE id = ?', [$offer->getId()]);
    }

    public function testTheDatabaseRefusesANegativeCounter(): void
    {
        $offer = SaleOfferFactory::createOne();
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();

        $this->expectException(DriverException::class);
        $connection->executeStatement('UPDATE sale_offer SET reserved_qty = -1 WHERE id = ?', [$offer->getId()]);
    }

    public function testTheSameProductCannotBeOfferedTwiceInASale(): void
    {
        $offer = SaleOfferFactory::createOne();

        $this->expectException(UniqueConstraintViolationException::class);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new SaleOffer($offer->getSale(), $offer->getProduct()));
        $entityManager->flush();
    }

    public function testFindForUpdateLocksTheRow(): void
    {
        $offer = SaleOfferFactory::createOne(['maxQty' => 3000]);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $locked = $entityManager->wrapInTransaction(
            static fn (): ?SaleOffer => $entityManager->getRepository(SaleOffer::class)->findForUpdate((int) $offer->getId()),
        );

        self::assertSame($offer->getId(), $locked?->getId());
    }
}
