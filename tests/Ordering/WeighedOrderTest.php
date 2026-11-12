<?php

declare(strict_types=1);

namespace App\Tests\Ordering;

use App\Catalog\Enum\ProductUnit;
use App\Ordering\Entity\Order;
use App\Ordering\Entity\OrderLine;
use App\Ordering\Exception\OrderNotAllowedException;
use App\Ordering\Model\OrderRequest;
use App\Ordering\Repository\OrderRepository;
use App\Ordering\Service\OrderManager;
use App\Sale\Entity\Sale;
use App\Sale\Entity\SaleOffer;
use App\Shared\Enum\Actor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Selling by weight: quantities in thousandths of a kilogram, selling steps, estimated amounts.
 */
final class WeighedOrderTest extends WebTestCase
{
    use Factories;
    use OrderingFixtures;

    public function testACustomerOrdersAWeightByStepsAndSeesAnEstimatedAmount(): void
    {
        $client = self::createClient();
        $sale = $this->createSale($this->shopOf($this->createProducer()));
        $tomatoes = $this->tomatoes($sale, 5);
        $bread = $this->createOffer($sale, 'Pain de campagne', 520);

        $crawler = $client->request('GET', $this->saleUrl($sale));
        $row = $crawler->filter(\sprintf('[data-offer="%d"]', self::idOf($tomatoes)));
        $input = $row->filter('input[type="number"]');
        self::assertSame('0.25', $input->attr('step'));
        self::assertSame('5', $input->attr('max'));
        self::assertSame('decimal', $input->attr('inputmode'));
        self::assertSame('250', $input->attr('data-step'));
        self::assertSame('500', $input->attr('data-minimum'));
        self::assertNotNull($input->attr('data-weighed'));
        self::assertStringContainsString("Par 0,25\u{00A0}kg, 0,5\u{00A0}kg au moins.", $row->filter('[data-weighed-help]')->text());
        self::assertSame("Retirer 0,25\u{00A0}kg de Tomates anciennes", $row->filter('button')->first()->attr('aria-label'));
        $breadInput = $crawler->filter(\sprintf('[data-offer="%d"] input[type="number"]', self::idOf($bread)));
        self::assertSame('1', $breadInput->attr('step'));
        self::assertNull($breadInput->attr('data-weighed'));

        $client->submitForm('Valider ma commande', $this->fields($sale, [self::idOf($tomatoes) => '1,5', self::idOf($bread) => '1']));

        self::assertResponseRedirects();
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailHtmlBodyContains($email, "1,5\u{00A0}kg Tomates anciennes");
        self::assertEmailHtmlBodyContains($email, 'environ');
        self::assertEmailTextBodyContains($email, 'Produits au poids : le montant exact est calculé à la pesée');

        $order = $this->onlyOrder();
        $line = $this->lineOf($order, 'Tomates anciennes');
        self::assertSame(1500, $line->getQuantity());
        self::assertSame(ProductUnit::Kg, $line->getUnit());
        self::assertSame(675, $line->getTotalCents());
        self::assertSame(675 + 520, $order->getTotalCents());
        self::assertSame(2, $order->getItemCount(), 'One loaf, one product sold by weight.');
        self::assertTrue($order->isEstimated());
        self::assertSame(1500, $this->reload(SaleOffer::class, self::idOf($tomatoes))->getReservedQty());

        $crawler = $client->followRedirect();
        $summary = $crawler->filter('[data-order-summary]')->text();
        self::assertStringContainsString("1,5\u{00A0}kg Tomates anciennes", $summary);
        self::assertStringContainsString('1 × Pain de campagne', $summary);
        self::assertStringContainsString("environ 11,95\u{00A0}€", $crawler->filter('[data-order-total]')->text());
        self::assertSelectorTextContains('[data-weighing-notice]', 'le montant exact est calculé à la pesée');
    }

    public function testTheBarCountsAndPricesTheBasketOnTheServerToo(): void
    {
        $client = self::createClient();
        $sale = $this->createSale($this->shopOf($this->createProducer()));
        $tomatoes = $this->tomatoes($sale, 5);
        $bread = $this->createOffer($sale, 'Pain de campagne', 520);

        $crawler = $client->request('GET', $this->saleUrl($sale));
        self::assertSelectorTextContains('[data-order-bar]', 'Panier vide');
        self::assertNotNull($crawler->filter('[data-order-bar] [data-order-form-target="estimate"]')->attr('hidden'));

        // An invalid weight shows the form again, with the bar computed by the server.
        $crawler = $client->submitForm('Valider ma commande', $this->fields($sale, [self::idOf($tomatoes) => '1,25', self::idOf($bread) => '2', 'firstName' => '']));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-order-bar]', '3 articles');
        // 1,25 kg × 4,50 € = 5,625 € → 5,63 €, plus two loaves.
        self::assertSelectorTextContains('[data-order-bar]', "16,03\u{a0}€");
        self::assertNull($crawler->filter('[data-order-bar] [data-order-form-target="estimate"]')->attr('hidden'));
        self::assertSame('1.25', $crawler->filter(\sprintf('input[name="order_form[quantities][%d]"]', self::idOf($tomatoes)))->attr('value'));
    }

    public function testACustomerCannotOrderOutsideTheSellingSteps(): void
    {
        $client = self::createClient();
        $sale = $this->createSale($this->shopOf($this->createProducer()));
        $tomatoes = $this->tomatoes($sale);
        $bread = $this->createOffer($sale, 'Pain de campagne', 520);

        foreach ([
            ['0,3', 'Choisis un multiple de 0,25 kg, 0,5 kg au moins.'],
            ['0,25', 'Choisis un multiple de 0,25 kg, 0,5 kg au moins.'],
            ['120', 'Choisis un poids entre 0 et 99 kg.'],
            ['un peu', 'Indique un poids en kilos, par exemple 1,5.'],
        ] as [$typed, $message]) {
            $client->request('GET', $this->saleUrl($sale));
            $client->submitForm('Valider ma commande', $this->fields($sale, [self::idOf($tomatoes) => $typed]));
            self::assertResponseStatusCodeSame(422, $typed);
            self::assertStringContainsString($message, (string) $client->getResponse()->getContent(), $typed);
        }

        $client->request('GET', $this->saleUrl($sale));
        $client->submitForm('Valider ma commande', $this->fields($sale, [self::idOf($bread) => '1,5']));
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Indique un nombre entier.', (string) $client->getResponse()->getContent());

        self::assertSame(0, self::getContainer()->get(OrderRepository::class)->count([]));
    }

    public function testAShortageOfAWeightSuggestsWhatCanStillBeOrdered(): void
    {
        $client = self::createClient();
        $sale = $this->createSale($this->shopOf($this->createProducer()));
        $tomatoes = $this->tomatoes($sale, 2);

        $client->request('GET', $this->saleUrl($sale));
        // Someone else takes 1,3 kg meanwhile: 0,7 kg left, so 0,5 kg can still be ordered (steps of 0,25 kg).
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->executeStatement('UPDATE sale_offer SET reserved_qty = 1300 WHERE id = ?', [self::idOf($tomatoes)]);

        $crawler = $client->submitForm('Valider ma commande', $this->fields($sale, [self::idOf($tomatoes) => '1']));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-stock-alert]', 'Il ne reste plus que 0,5 kg de « Tomates anciennes ».');
        self::assertSame('0.5', $crawler->filter(\sprintf('input[name="order_form[quantities][%d]"]', self::idOf($tomatoes)))->attr('value'));

        // Less than the minimum left: sold out.
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->executeStatement('UPDATE sale_offer SET reserved_qty = 1600 WHERE id = ?', [self::idOf($tomatoes)]);
        $crawler = $client->request('GET', $this->saleUrl($sale));
        self::assertSame('Épuisé', trim($crawler->filter(\sprintf('[data-offer="%d"] [data-sold-out]', self::idOf($tomatoes)))->text()));
    }

    public function testAProducerMayTypeAnyWeightButNoFractionOfAPiece(): void
    {
        $client = self::createClient();
        $producer = $this->createProducer();
        $sale = $this->createSale($this->shopOf($producer));
        $tomatoes = $this->tomatoes($sale);
        $bread = $this->createOffer($sale, 'Pain de campagne', 520);
        $client->loginUser($producer, 'producer');
        $url = \sprintf('/pro/ventes/%d/commandes/nouvelle', self::idOf($sale));

        $crawler = $client->request('GET', $url);
        $input = $crawler->filter(\sprintf('input[name="order_form[quantities][%d]"]', self::idOf($tomatoes)));
        self::assertSame('0.001', $input->attr('step'));
        self::assertStringContainsString('au kilo', $crawler->filter(\sprintf('[data-offer="%d"]', self::idOf($tomatoes)))->text());

        $identity = [
            'order_form[delivery]' => (string) $this->deliveryOf($sale)->getId(),
            'order_form[email]' => 'jeanne@example.test',
            'order_form[firstName]' => 'Jeanne',
            'order_form[lastName]' => 'Durand',
        ];
        $client->submitForm('Ajouter la commande', $identity + [\sprintf('order_form[quantities][%d]', self::idOf($bread)) => '0,5']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Indique un nombre entier.', (string) $client->getResponse()->getContent());

        $client->submitForm('Ajouter la commande', $identity + [\sprintf('order_form[quantities][%d]', self::idOf($tomatoes)) => '0,3']);
        self::assertResponseRedirects();
        $order = $this->onlyOrder();
        self::assertSame(300, $this->lineOf($order, 'Tomates anciennes')->getQuantity());
        self::assertSame(135, $order->getTotalCents());
    }

    public function testOrderManagerRefusesAFractionOfAPiece(): void
    {
        self::bootKernel();
        $sale = $this->createSale($this->shopOf($this->createProducer()));
        $bread = $this->createOffer($sale, 'Pain de campagne', 520);

        $this->expectException(OrderNotAllowedException::class);
        $this->expectExceptionMessageIsOrContains('« Pain de campagne » se vend à l\'unité : indique un nombre entier.');

        self::getContainer()->get(OrderManager::class)->placeOrder($sale, $this->createCustomer(), new OrderRequest($this->deliveryOf($sale), [self::idOf($bread) => 1500]), Actor::Producer);
    }

    public function testALineRefusesAFractionOfAPiece(): void
    {
        self::bootKernel();
        $sale = $this->createSale($this->shopOf($this->createProducer()));
        $bread = $this->createOffer($sale, 'Pain de campagne', 520);
        $order = $this->placeOrder($sale, $this->createCustomer(), [self::idOf($bread) => 1]);
        $line = $this->lineOf($order, 'Pain de campagne');

        try {
            $line->setQuantity(1500);
            self::fail('A fraction of a loaf cannot be ordered.');
        } catch (\InvalidArgumentException) {
            self::assertSame(1000, $line->getQuantity());
        }

        $this->expectException(\InvalidArgumentException::class);
        $bread->reserve(1);
    }

    public function testTheSaleFormTakesALimitInKilograms(): void
    {
        $client = self::createClient();
        $producer = $this->createProducer();
        $sale = $this->createSale($this->shopOf($producer));
        $tomatoes = $this->tomatoes($sale, 5);
        $bread = $this->createOffer($sale, 'Pain de campagne', 520, 10);
        $this->placeOrder($sale, $this->createCustomer(), [self::idOf($bread) => 2]);
        $client->loginUser($producer, 'producer');

        $crawler = $client->request('GET', \sprintf('/pro/ventes/%d/modifier', self::idOf($sale)));
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Enregistrer')->form();
        $values = [];
        foreach (array_keys($form->all()) as $name) {
            if (str_ends_with($name, '[maxQty]')) {
                $values[$name] = '12,5';
            }
        }
        self::assertCount(2, $values);
        self::assertStringContainsString('2 déjà réservés', $crawler->filter('main')->text());
        self::assertStringContainsString('Quantité max. (kg)', $crawler->filter('main')->text());

        $client->submit($form, $values);

        self::assertResponseStatusCodeSame(422, 'A fraction of a loaf is refused.');
        self::assertStringContainsString('Ce produit se vend à l&#039;unité : indique un nombre entier.', (string) $client->getResponse()->getContent());
        self::assertSame(5000, $this->reloadOffer($tomatoes)->getMaxQty());
    }

    private function tomatoes(Sale $sale, ?int $maxKg = null): SaleOffer
    {
        return $this->createOffer($sale, 'Tomates anciennes', 450, $maxKg, ['unit' => ProductUnit::Kg, 'quantityStep' => 250, 'minQuantity' => 500]);
    }

    /**
     * @param array<int|string, string> $values quantities by offer id, or other order_form fields
     *
     * @return array<string, string>
     */
    private function fields(Sale $sale, array $values): array
    {
        $fields = [
            'order_form[delivery]' => (string) $this->deliveryOf($sale)->getId(),
            'order_form[email]' => 'jeanne@example.test',
            'order_form[firstName]' => 'Jeanne',
            'order_form[lastName]' => 'Durand',
            'order_form[acceptTerms]' => '1',
        ];
        foreach ($values as $key => $value) {
            $fields[\is_int($key) ? \sprintf('order_form[quantities][%d]', $key) : \sprintf('order_form[%s]', $key)] = $value;
        }

        return $fields;
    }

    private function saleUrl(Sale $sale): string
    {
        return \sprintf('/%s/vente/%s', $sale->getShop()->getSlug(), $sale->getCode());
    }

    private function onlyOrder(): Order
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $orders = self::getContainer()->get(OrderRepository::class)->findAll();
        self::assertCount(1, $orders);

        return $orders[0];
    }

    private function lineOf(Order $order, string $label): OrderLine
    {
        foreach ($order->getLines() as $line) {
            if ($line->getLabel() === $label) {
                return $line;
            }
        }

        throw new \LogicException(\sprintf('No line "%s".', $label));
    }

    private function reloadOffer(SaleOffer $offer): SaleOffer
    {
        return $this->reload(SaleOffer::class, self::idOf($offer));
    }
}
