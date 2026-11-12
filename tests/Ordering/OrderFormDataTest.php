<?php

declare(strict_types=1);

namespace App\Tests\Ordering;

use App\Catalog\Enum\ProductUnit;
use App\Ordering\Entity\OrderLine;
use App\Ordering\Exception\InsufficientStockException;
use App\Ordering\Exception\StockShortage;
use App\Ordering\Form\OrderFormData;
use App\Ordering\Form\OrderFormType;
use App\Sale\Entity\Sale;
use App\Sale\Entity\SaleOffer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;
use Zenstruck\Foundry\Test\Factories;

/**
 * The basket behind the order form, in thousandths of the unit.
 */
final class OrderFormDataTest extends KernelTestCase
{
    use Factories;
    use OrderingFixtures;

    private Sale $sale;
    private SaleOffer $bread;
    private SaleOffer $tomatoes;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->sale = $this->createSale($this->shopOf($this->createProducer()));
        $this->bread = $this->createOffer($this->sale, 'Pain', 520);
        $this->tomatoes = $this->createOffer($this->sale, 'Tomates', 450, null, ['unit' => ProductUnit::Kg, 'quantityStep' => 250, 'minQuantity' => 500]);
    }

    public function testTheBasketCountsAndPricesTheSelectedProducts(): void
    {
        $data = new OrderFormData();
        self::assertSame(0, $data->itemCount($this->sale));
        self::assertSame(0, $data->estimatedTotalCents($this->sale));
        self::assertFalse($data->hasWeighedItems($this->sale));
        self::assertFalse($data->hasItems());

        $data->quantities = [self::idOf($this->bread) => 3000, self::idOf($this->tomatoes) => 0];
        self::assertSame(3, $data->itemCount($this->sale));
        self::assertFalse($data->hasWeighedItems($this->sale), 'No tomato in the basket.');
        self::assertTrue($data->hasItems());

        $data->quantities[self::idOf($this->tomatoes)] = 1250;
        self::assertSame(4, $data->itemCount($this->sale), 'Three loaves and one product sold by weight.');
        self::assertTrue($data->hasWeighedItems($this->sale));
        // 3 × 5,20 € + 1,25 kg × 4,50 € (5,625 € → 5,63 €).
        self::assertSame(1560 + 563, $data->estimatedTotalCents($this->sale));
    }

    public function testLinesOfTheEditedOrderKeepTheirFrozenPrice(): void
    {
        $order = $this->placeOrder($this->sale, $this->createCustomer(), [self::idOf($this->bread) => 1]);
        $this->bread->setPriceCents(600);
        $this->tomatoes->setPriceCents(500);

        $data = OrderFormData::fromOrder($order);
        $data->quantities[self::idOf($this->tomatoes)] = 1000;

        self::assertSame(520 + 500, $data->estimatedTotalCents($this->sale, $order));
        self::assertSame(600 + 500, $data->estimatedTotalCents($this->sale));
    }

    public function testActualWeightsAreOnlyKeptForTheWeighedLinesOfTheOrder(): void
    {
        $order = $this->placeOrder($this->sale, $this->createCustomer(), [self::idOf($this->bread) => 1]);
        $onions = $this->createOffer($this->sale, 'Oignons', 300, null, ['unit' => ProductUnit::Kg]);
        $tomatoLine = new OrderLine($order, $this->tomatoes, 1000);
        $tomatoLine->setActualQuantity(1100);
        $onionLine = new OrderLine($order, $onions, 2000);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $tomatoId = (int) $tomatoLine->getId();
        $onionId = (int) $onionLine->getId();

        $data = OrderFormData::fromOrder($order);
        self::assertSame([$tomatoId => 1100, $onionId => null], $data->actualQuantities);

        $data->actualQuantities[$onionId] = 1900;
        $data->actualQuantities[999_999] = 5000;
        self::assertSame([$tomatoId => 1100, $onionId => 1900], $data->weightsFor($order));

        $order->removeLine($onionLine);
        self::assertSame([$tomatoId => 1100], $data->weightsFor($order), 'A removed line is left out.');
    }

    public function testPrefillKeepsWhatCanStillBeOrdered(): void
    {
        $previous = $this->placeOrder($this->sale, $this->createCustomer(), [self::idOf($this->bread) => 2]);
        new OrderLine($previous, $this->tomatoes, 1600);
        $next = $this->createSale($this->sale->getShop());
        $nextTomatoes = new SaleOffer($next, $this->tomatoes->getProduct(), null, null, 1300);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $data = new OrderFormData();
        $data->prefillFrom($previous, $next);

        // 1,3 kg left, by steps of 0,25 kg: 1,25 kg.
        self::assertSame([self::idOf($nextTomatoes) => 1250], $data->quantities);
    }

    public function testShortagesLowerTheBasketToWhatCanStillBeOrdered(): void
    {
        $order = $this->placeOrder($this->sale, $this->createCustomer(), [self::idOf($this->bread) => 1]);
        $data = OrderFormData::fromOrder($order);

        $messages = $data->applyShortages(new InsufficientStockException([
            new StockShortage(1, 'Brioche', 2000, 0),
            new StockShortage(2, 'Baguette', 3000, 1000),
            new StockShortage(3, 'Fougasse', 5000, 3000),
            new StockShortage(4, 'Tomates', 1000, 700, ProductUnit::Kg, 250, 500),
            new StockShortage(5, 'Courgettes', 1000, 400, ProductUnit::Kg, 250, 500),
            new StockShortage(self::idOf($this->bread), 'Pain', 3000, 1000),
        ]), $order);

        self::assertSame([
            '« Brioche » vient de partir : il n\'en reste plus.',
            'Il ne reste plus qu\'un seul « Baguette ».',
            'Il ne reste plus que 3 « Fougasse ».',
            'Il ne reste plus que 0,5 kg de « Tomates ».',
            '« Courgettes » vient de partir : il n\'en reste plus.',
            'Il ne reste plus que 2 « Pain ».',
        ], $messages);
        self::assertSame([0, 1000, 3000, 500, 0], [$data->quantities[1], $data->quantities[2], $data->quantities[3], $data->quantities[4], $data->quantities[5]]);
        self::assertSame(2000, $data->quantities[self::idOf($this->bread)], 'The loaf already in the order counts.');
    }

    public function testFormOptionsAreTyped(): void
    {
        $forms = self::getContainer()->get(FormFactoryInterface::class);

        $failures = 0;
        foreach (['email_optional' => 'yes', 'selling_steps' => 1, 'weights' => 'order'] as $option => $value) {
            try {
                $forms->create(OrderFormType::class, new OrderFormData(), ['sale' => $this->sale, $option => $value]);
            } catch (InvalidOptionsException) {
                ++$failures;
            }
        }

        self::assertSame(3, $failures);
    }
}
