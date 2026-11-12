<?php

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\Entity\Product;
use App\Catalog\Enum\ProductUnit;
use App\Catalog\Repository\ProductRepository;
use App\Shop\Entity\Shop;
use App\Tests\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * Products sold by weight: selling step, minimum and quantities in thousandths of a kilogram.
 */
final class WeighedProductTest extends WebTestCase
{
    use Factories;

    public function testAProductSoldByWeightTakesMultiplesOfItsStepFromItsMinimum(): void
    {
        $tomatoes = $this->product(ProductUnit::Kg)->setQuantityStep(250)->setMinQuantity(500);

        self::assertSame(250, $tomatoes->getQuantityStep());
        self::assertSame(500, $tomatoes->getMinQuantity());
        self::assertTrue($tomatoes->acceptsOrderQuantity(500));
        self::assertTrue($tomatoes->acceptsOrderQuantity(1750));
        self::assertFalse($tomatoes->acceptsOrderQuantity(250), 'Below the minimum.');
        self::assertFalse($tomatoes->acceptsOrderQuantity(600), 'Not a multiple of the step.');
        self::assertFalse($tomatoes->acceptsOrderQuantity(0));

        // The minimum is never below the step.
        $tomatoes->setMinQuantity(100);
        self::assertSame(250, $tomatoes->getMinQuantity());
        self::assertTrue($tomatoes->acceptsOrderQuantity(250));
    }

    public function testAProductSoldByTheUnitIgnoresTheWeightSettings(): void
    {
        // Settings that would be invalid by weight are ignored by the unit.
        $bread = $this->product(ProductUnit::Piece)->setQuantityStep(300)->setMinQuantity(500);

        self::assertSame(1000, $bread->getQuantityStep());
        self::assertSame(1000, $bread->getMinQuantity());
        self::assertTrue($bread->acceptsOrderQuantity(1000));
        self::assertTrue($bread->acceptsOrderQuantity(3000));
        self::assertFalse($bread->acceptsOrderQuantity(1500));
        self::assertFalse($bread->acceptsOrderQuantity(500));
        self::assertSame([], $this->violations($bread));
    }

    public function testValidation(): void
    {
        self::assertSame([], $this->violations($this->product(ProductUnit::Kg)->setQuantityStep(500)->setMinQuantity(1500)->setMaxQtyDefault(12500)));
        self::assertSame(['quantityStep'], $this->violations($this->product(ProductUnit::Kg)->setQuantityStep(300)));
        self::assertSame(['minQuantity'], $this->violations($this->product(ProductUnit::Kg)->setQuantityStep(500)->setMinQuantity(750)));
        self::assertSame(['minQuantity'], $this->violations($this->product(ProductUnit::Kg)->setQuantityStep(500)->setMinQuantity(0)));
        self::assertSame(['maxQtyDefault'], $this->violations($this->product(ProductUnit::Bunch)->setMaxQtyDefault(2500)));
        self::assertSame(['maxQtyDefault'], $this->violations($this->product(ProductUnit::Piece)->setMaxQtyDefault(0)));
    }

    public function testAProducerCreatesAProductSoldByWeight(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(), 'producer');

        $crawler = $client->request('GET', '/pro/produits/nouveau');
        self::assertCount(1, $crawler->filter('[data-weighed-panel] select[name="product[quantityStep]"]'));
        foreach (['product_maxQtyDefault' => 'Quantité maximale par vente', 'product_quantityStep' => 'Pas de vente', 'product_minQuantity' => 'Minimum par commande (kg)'] as $id => $label) {
            self::assertStringStartsWith($label, trim($crawler->filter(\sprintf('label[for="%s"]', $id))->text()));
        }
        self::assertSame('decimal', $crawler->filter('#product_maxQtyDefault')->attr('inputmode'));
        self::assertSame('decimal', $crawler->filter('#product_minQuantity')->attr('inputmode'));
        $client->submitForm('Enregistrer', [
            'product[name]' => 'Tomates anciennes',
            'product[priceCents]' => '4,50',
            'product[unit]' => 'kg',
            'product[kind]' => 'permanent',
            'product[maxQtyDefault]' => '12,5',
            'product[quantityStep]' => '250',
            'product[minQuantity]' => '0,5',
        ]);

        self::assertResponseRedirects('/pro/produits');
        $product = self::getContainer()->get(ProductRepository::class)->findOneBy(['name' => 'Tomates anciennes']);
        self::assertInstanceOf(Product::class, $product);
        self::assertSame(ProductUnit::Kg, $product->getUnit());
        self::assertSame(450, $product->getPriceCents());
        self::assertSame(12500, $product->getMaxQtyDefault());
        self::assertSame(250, $product->getQuantityStep());
        self::assertSame(500, $product->getMinQuantity());

        $client->followRedirect();
        self::assertSelectorTextContains(\sprintf('[data-product="%d"]', $product->getId()), "12,5\u{00A0}kg max par vente");
        self::assertSelectorTextContains(\sprintf('[data-product="%d"]', $product->getId()), "par 0,25\u{00A0}kg");
    }

    public function testAFractionOfAPieceOrAMisalignedMinimumIsRefused(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(), 'producer');

        $client->request('GET', '/pro/produits/nouveau');
        $client->submitForm('Enregistrer', [
            'product[name]' => 'Pain',
            'product[priceCents]' => '5,20',
            'product[unit]' => 'piece',
            'product[maxQtyDefault]' => '2,5',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Ce produit se vend à l&#039;unité : indique un nombre entier.', (string) $client->getResponse()->getContent());

        $client->submitForm('Enregistrer', [
            'product[name]' => 'Pain',
            'product[priceCents]' => '5,20',
            'product[unit]' => 'kg',
            'product[maxQtyDefault]' => 'beaucoup',
            'product[quantityStep]' => '500',
            'product[minQuantity]' => '0,75',
        ]);
        self::assertResponseStatusCodeSame(422);
        $page = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Indique une quantité, par exemple 1,5.', $page);
        self::assertStringContainsString('Le minimum doit être un multiple du pas de vente.', $page);
        self::assertSame(0, self::getContainer()->get(ProductRepository::class)->count([]));
    }

    private function product(ProductUnit $unit): Product
    {
        return new Product(new Shop('Potager', 'potager'), 'Produit', 450)->setUnit($unit);
    }

    /**
     * @return list<string> property paths of the violations
     */
    private function violations(Product $product): array
    {
        $paths = [];
        foreach (self::getContainer()->get(ValidatorInterface::class)->validate($product) as $violation) {
            $paths[] = $violation->getPropertyPath();
        }

        return array_values(array_unique($paths));
    }
}
