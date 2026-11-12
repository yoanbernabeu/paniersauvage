<?php

declare(strict_types=1);

namespace App\Tests\Ordering;

use App\Catalog\Entity\Product;
use App\Kernel;
use App\Ordering\Exception\InsufficientStockException;
use App\Ordering\Service\ReservationService;
use App\Sale\Entity\Sale;
use App\Sale\Entity\SaleOffer;
use App\Shared\Service\Quantity;
use App\Shared\Service\RandomCode;
use App\Shop\Entity\Shop;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Proves the "no overselling" rule (SPEC §1.2) with real, distinct PostgreSQL connections.
 *
 * dama/doctrine-test-bundle normally makes every connection of a test share one transaction,
 * which would hide exactly what is tested here. #[SkipDatabaseRollback] turns that off for this
 * class: data is really committed, so each test removes what it created in tearDown().
 */
#[SkipDatabaseRollback]
final class ReservationConcurrencyTest extends KernelTestCase
{
    private const int STOCK = 5;

    private ?int $shopId = null;
    private ?int $saleId = null;
    private ?int $productId = null;
    private ?int $offerId = null;
    private ?Kernel $secondKernel = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $suffix = mb_strtolower(RandomCode::generate(8));
        $shop = new Shop('Fournil concurrence', 'concurrence-'.$suffix);
        $product = new Product($shop, 'Brioche', 650);
        $sale = new Sale($shop, RandomCode::generate(Sale::CODE_LENGTH), new \DateTimeImmutable('+7 days'), new \DateTimeImmutable('-1 day'), new \DateTimeImmutable('+4 days'), new \DateTimeImmutable('-2 days'), 'Vente concurrence');
        $sale->open();
        $offer = new SaleOffer($sale, $product, null, null, Quantity::units(self::STOCK));

        $entityManager->persist($shop);
        $entityManager->persist($product);
        $entityManager->persist($sale);
        $entityManager->flush();

        $this->shopId = $shop->getId();
        $this->saleId = $sale->getId();
        $this->productId = $product->getId();
        $this->offerId = $offer->getId();
    }

    protected function tearDown(): void
    {
        $this->secondKernel?->shutdown();
        $this->secondKernel = null;

        // Committed for real: remove it by hand, on a fresh connection state.
        self::ensureKernelShutdown();
        self::bootKernel();
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement('DELETE FROM sale_offer WHERE sale_id = ?', [$this->saleId]);
        $connection->executeStatement('DELETE FROM sale WHERE id = ?', [$this->saleId]);
        $connection->executeStatement('DELETE FROM product WHERE id = ?', [$this->productId]);
        $connection->executeStatement('DELETE FROM shop WHERE id = ?', [$this->shopId]);

        parent::tearDown();
    }

    /**
     * Two connections in this process: while the first one holds its reservation open, the second
     * one cannot even read the counter (row lock); once the first commits, the second decides on
     * the committed value.
     */
    public function testASecondConnectionWaitsForTheFirstThenSeesItsReservation(): void
    {
        $firstEntityManager = self::getContainer()->get(EntityManagerInterface::class);
        $firstReservations = self::getContainer()->get(ReservationService::class);
        $firstConnection = $firstEntityManager->getConnection();

        // A second kernel: its own container, entity manager and PostgreSQL session.
        $this->secondKernel = new Kernel('test', true);
        $this->secondKernel->boot();
        $secondEntityManager = $this->secondKernel->getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $secondEntityManager);
        self::assertNotSame($firstEntityManager, $secondEntityManager);
        $secondReservations = $this->secondKernel->getContainer()->get(ReservationService::class);
        self::assertNotSame($firstReservations, $secondReservations);
        $secondConnection = $secondEntityManager->getConnection();

        self::assertNotSame(
            $firstConnection->fetchOne('SELECT pg_backend_pid()'),
            $secondConnection->fetchOne('SELECT pg_backend_pid()'),
            'The two kernels must use two distinct PostgreSQL sessions.',
        );

        self::assertNotNull($this->offerId);
        $firstOffer = $firstEntityManager->find(SaleOffer::class, $this->offerId);
        $secondOffer = $secondEntityManager->find(SaleOffer::class, $this->offerId);
        self::assertNotNull($firstOffer);
        self::assertNotNull($secondOffer);
        self::assertNotSame($firstOffer, $secondOffer);

        // First customer: 3 of the 5 brioches, transaction still open.
        $firstConnection->beginTransaction();
        $firstReservations->reserve($firstOffer, 3000);

        // Second customer wants 3 too: blocked by the row lock instead of reading "5 left".
        $secondConnection->executeStatement("SET lock_timeout = '300ms'");
        $blocked = null;
        try {
            $secondReservations->reserve($secondOffer, 3000);
        } catch (\Throwable $exception) {
            $blocked = $exception;
        }
        self::assertInstanceOf(DriverException::class, $blocked, 'The second connection must wait for the row lock.');
        self::assertSame('55P03', $blocked->getSQLState(), 'Expected PostgreSQL lock_not_available.');
        self::assertFalse($secondConnection->isTransactionActive());

        $firstConnection->commit();

        // Now it sees the committed counter: 2 left, 3 refused, 2 accepted.
        try {
            $secondReservations->reserve($secondOffer, 3000);
            self::fail('Only two brioches are left.');
        } catch (InsufficientStockException $exception) {
            self::assertSame(2000, $exception->getShortages()[0]->remaining);
        }
        $secondReservations->reserve($secondOffer, 2000);

        self::assertSame(Quantity::units(self::STOCK), $this->reservedQtyInDatabase());
    }

    /**
     * Twelve separate PHP processes each try to take one of the five brioches at the same instant.
     */
    public function testParallelProcessesNeverOversell(): void
    {
        $php = new PhpExecutableFinder()->find();
        self::assertIsString($php);

        $workers = 12;
        $startAt = microtime(true) + 2.0;
        $processes = [];

        for ($i = 0; $i < $workers; ++$i) {
            $process = new Process(
                [$php, __DIR__.'/Support/reserve_worker.php', (string) $this->offerId, '1000', (string) $startAt],
                \dirname(__DIR__, 2),
                ['APP_ENV' => 'test', 'APP_DEBUG' => '1'],
            );
            $process->setTimeout(60);
            $process->start();
            $processes[] = $process;
        }

        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            self::assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
            $results[] = trim($process->getOutput());
        }

        $counts = array_count_values($results);
        self::assertSame(self::STOCK, $counts['RESERVED'] ?? 0, 'Exactly the available stock is reserved.');
        self::assertSame($workers - self::STOCK, $counts['REFUSED'] ?? 0, 'Everyone else is refused cleanly.');
        self::assertSame(Quantity::units(self::STOCK), $this->reservedQtyInDatabase());
    }

    private function reservedQtyInDatabase(): int
    {
        $value = self::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->fetchOne('SELECT reserved_qty FROM sale_offer WHERE id = ?', [$this->offerId]);
        self::assertIsNumeric($value);

        return (int) $value;
    }
}
