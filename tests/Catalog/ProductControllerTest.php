<?php

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\Entity\Product;
use App\Catalog\Enum\ProductKind;
use App\Catalog\Enum\ProductUnit;
use App\Catalog\Repository\ProductRepository;
use App\Tests\Factory\ProductFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Shop\UploadTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

final class ProductControllerTest extends WebTestCase
{
    use Factories;
    use UploadTestTrait;

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function producerRoutes(): iterable
    {
        yield 'index' => ['GET', '/pro/produits'];
        yield 'new' => ['GET', '/pro/produits/nouveau'];
        yield 'edit' => ['GET', '/pro/produits/1/modifier'];
        yield 'archive' => ['POST', '/pro/produits/1/archiver'];
        yield 'move' => ['POST', '/pro/produits/1/deplacer'];
    }

    #[DataProvider('producerRoutes')]
    public function testRoutesRequireAuthentication(string $method, string $path): void
    {
        $client = self::createClient();
        $client->request($method, $path);

        self::assertResponseRedirects('/pro/connexion');
    }

    #[DataProvider('producerRoutes')]
    public function testAProducerWithoutShopIsDenied(string $method, string $path): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::new()->withoutShop()->create(), 'producer');
        $client->request($method, $path);

        self::assertResponseStatusCodeSame(403);
    }

    public function testEmptyCatalogueShowsAnEmptyState(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(), 'producer');
        $client->request('GET', '/pro/produits');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-slot="empty"]', 'Ton catalogue est vide');
        self::assertSelectorExists('[data-slot="empty"] a[href="/pro/produits/nouveau"]');
    }

    public function testIndexSeparatesPermanentSeasonalAndArchivedProducts(): void
    {
        $client = self::createClient();
        $producer = UserFactory::createOne();
        $shop = $producer->getShop();
        ProductFactory::createOne(['shop' => $shop, 'name' => 'Pain de campagne', 'priceCents' => 520, 'vatRateBp' => 550, 'position' => 2]);
        ProductFactory::createOne(['shop' => $shop, 'name' => 'Baguette', 'priceCents' => 130, 'position' => 1, 'maxQtyDefault' => 40000]);
        ProductFactory::new()
            ->seasonal(new \DateTimeImmutable('2026-12-01'), new \DateTimeImmutable('2026-12-31'))
            ->create(['shop' => $shop, 'name' => 'Brioche de Noël']);
        ProductFactory::new()->archived()->create(['shop' => $shop, 'name' => 'Pain aux noix']);
        // Somebody else's product never shows up.
        ProductFactory::createOne(['name' => 'Miel du voisin']);
        $client->loginUser($producer, 'producer');

        $crawler = $client->request('GET', '/pro/produits');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['Baguette', 'Pain de campagne'],
            $crawler->filter('[data-section="permanent"] [data-product] p.truncate')->each(static fn ($node): string => trim($node->text())),
        );
        self::assertSelectorTextContains('[data-section="permanent"]', "5,20\u{00A0}€");
        self::assertSelectorTextContains('[data-section="permanent"]', '/ pièce');
        self::assertSelectorTextContains('[data-section="permanent"]', '40 max par vente');
        self::assertSelectorTextContains('[data-section="seasonal"]', 'Brioche de Noël');
        self::assertSelectorTextNotContains('[data-section="permanent"]', 'Brioche de Noël');
        self::assertSelectorTextContains('[data-section="archived"]', 'Pain aux noix');
        self::assertSelectorTextContains('[data-section="archived"]', 'Désarchiver');
        self::assertSelectorTextNotContains('main', 'Miel du voisin');
    }

    public function testCreatesAProductWithPriceInCents(): void
    {
        $client = self::createClient();
        $producer = UserFactory::createOne();
        $client->loginUser($producer, 'producer');

        $client->request('GET', '/pro/produits/nouveau');
        self::assertResponseIsSuccessful();
        $client->submitForm('Enregistrer', [
            'product[name]' => 'Pain de campagne',
            'product[description]' => 'Au levain naturel, 800 g.',
            'product[priceCents]' => '5,20',
            'product[vatRateBp]' => '550',
            'product[unit]' => 'piece',
            'product[kind]' => 'permanent',
            'product[maxQtyDefault]' => '30',
            'product[allergens]' => 'gluten',
            'product[labels]' => 'Bio,  Levain naturel , ',
        ]);

        self::assertResponseRedirects('/pro/produits');
        $client->followRedirect();
        self::assertSelectorTextContains('[data-flash="success"]', 'Pain de campagne');

        $product = $this->product('Pain de campagne');
        self::assertSame($producer->getShop()?->getId(), $product->getShop()->getId());
        self::assertSame('Au levain naturel, 800 g.', $product->getDescription());
        self::assertSame(520, $product->getPriceCents());
        self::assertSame(550, $product->getVatRateBp());
        self::assertSame(ProductUnit::Piece, $product->getUnit());
        self::assertSame(ProductKind::Permanent, $product->getKind());
        self::assertSame(30000, $product->getMaxQtyDefault());
        self::assertSame('gluten', $product->getAllergens());
        self::assertSame(['Bio', 'Levain naturel'], $product->getLabels());
        self::assertSame(1, $product->getPosition(), 'A new product goes last (ordered from the list).');
        self::assertFalse($product->isArchived());
        self::assertNull($product->getPhotoPath());
    }

    public function testOffersTheFiveVatRates(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(), 'producer');
        $crawler = $client->request('GET', '/pro/produits/nouveau');

        self::assertSame(
            ['0' => '0 %', '210' => '2,1 %', '550' => '5,5 %', '1000' => '10 %', '2000' => '20 %'],
            array_combine(
                $crawler->filter('select[name="product[vatRateBp]"] option')->each(static fn ($option): string => (string) $option->attr('value')),
                $crawler->filter('select[name="product[vatRateBp]"] option')->each(static fn ($option): string => $option->text()),
            ),
        );
        // 5.5 % is preselected for a new product.
        self::assertSame('550', $crawler->filter('select[name="product[vatRateBp]"] option[selected]')->attr('value'));
    }

    public function testCreatesASeasonalProductWithItsPeriod(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(), 'producer');

        $client->request('GET', '/pro/produits/nouveau');
        $client->submitForm('Enregistrer', [
            'product[name]' => 'Brioche de Noël',
            'product[priceCents]' => '12',
            'product[vatRateBp]' => '1000',
            'product[unit]' => 'kg',
            'product[kind]' => 'seasonal',
            'product[availableFrom]' => '2026-12-01',
            'product[availableUntil]' => '2026-12-31',
        ]);

        self::assertResponseRedirects('/pro/produits');

        $product = $this->product('Brioche de Noël');
        self::assertSame(1200, $product->getPriceCents());
        self::assertSame(1000, $product->getVatRateBp());
        self::assertSame(ProductUnit::Kg, $product->getUnit());
        self::assertTrue($product->isSeasonal());
        self::assertSame('2026-12-01', $product->getAvailableFrom()?->format('Y-m-d'));
        self::assertSame('2026-12-31', $product->getAvailableUntil()?->format('Y-m-d'));
        self::assertNull($product->getMaxQtyDefault());

        $client->followRedirect();
        self::assertSelectorTextContains('[data-section="seasonal"]', 'Brioche de Noël');
    }

    public function testAPermanentProductKeepsNoPeriod(): void
    {
        $client = self::createClient();
        $producer = UserFactory::createOne();
        $product = ProductFactory::new()
            ->seasonal(new \DateTimeImmutable('2026-12-01'), new \DateTimeImmutable('2026-12-31'))
            ->create(['shop' => $producer->getShop(), 'name' => 'Brioche']);
        $client->loginUser($producer, 'producer');

        $client->request('GET', '/pro/produits/'.$product->getId().'/modifier');
        $client->submitForm('Enregistrer', ['product[kind]' => 'permanent']);

        self::assertResponseRedirects('/pro/produits');
        $updated = $this->product('Brioche');
        self::assertTrue($updated->isPermanent());
        self::assertNull($updated->getAvailableFrom());
        self::assertNull($updated->getAvailableUntil());
    }

    public function testInvalidProductIsRejected(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(), 'producer');

        $client->request('GET', '/pro/produits/nouveau');
        $client->submitForm('Enregistrer', [
            'product[name]' => '',
            'product[priceCents]' => 'cher',
            'product[kind]' => 'seasonal',
            'product[availableFrom]' => '2026-12-31',
            'product[availableUntil]' => '2026-12-01',
            'product[maxQtyDefault]' => '0',
        ]);

        self::assertResponseStatusCodeSame(422);
        $page = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Indique un prix en euros, par exemple 5,20.', $page);
        self::assertStringContainsString('La fin de période doit suivre le début.', $page);
        self::assertSame(0, self::getContainer()->get(ProductRepository::class)->count([]));
    }

    public function testANegativePriceIsRejected(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(), 'producer');

        $client->request('GET', '/pro/produits/nouveau');
        $client->submitForm('Enregistrer', ['product[name]' => 'Pain', 'product[priceCents]' => '-2']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, self::getContainer()->get(ProductRepository::class)->count([]));
    }

    public function testEditsAProduct(): void
    {
        $client = self::createClient();
        $producer = UserFactory::createOne();
        $product = ProductFactory::createOne(['shop' => $producer->getShop(), 'name' => 'Pain de campagne', 'priceCents' => 520, 'labels' => ['Bio']]);
        $client->loginUser($producer, 'producer');

        $client->request('GET', '/pro/produits/'.$product->getId().'/modifier');
        self::assertResponseIsSuccessful();
        self::assertInputValueSame('product[name]', 'Pain de campagne');
        self::assertInputValueSame('product[priceCents]', '5,20');
        self::assertInputValueSame('product[labels]', 'Bio');

        $client->submitForm('Enregistrer', [
            'product[name]' => 'Pain de campagne 1 kg',
            'product[priceCents]' => '6,5',
            'product[vatRateBp]' => '2000',
            'product[unit]' => 'bunch',
        ]);

        self::assertResponseRedirects('/pro/produits');
        $updated = $this->product('Pain de campagne 1 kg');
        self::assertSame($product->getId(), $updated->getId());
        self::assertSame(650, $updated->getPriceCents());
        self::assertSame(2000, $updated->getVatRateBp());
        self::assertSame(ProductUnit::Bunch, $updated->getUnit());
    }

    public function testArchivesAndRestoresAProduct(): void
    {
        $client = self::createClient();
        $producer = UserFactory::createOne();
        $product = ProductFactory::createOne(['shop' => $producer->getShop(), 'name' => 'Pain aux noix']);
        $client->loginUser($producer, 'producer');

        $crawler = $client->request('GET', '/pro/produits');
        $client->submit($crawler->filter('[data-product="'.$product->getId().'"] form')->form());

        self::assertResponseRedirects('/pro/produits');
        self::assertTrue($this->product('Pain aux noix')->isArchived());

        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('[data-flash="success"]', 'est archivé');
        self::assertSelectorTextContains('[data-section="archived"]', 'Pain aux noix');

        $client->submit($crawler->filter('[data-section="archived"] [data-product="'.$product->getId().'"] form')->form());

        self::assertResponseRedirects('/pro/produits');
        self::assertFalse($this->product('Pain aux noix')->isArchived());
    }

    public function testArchiveRequiresAValidCsrfToken(): void
    {
        $client = self::createClient();
        $producer = UserFactory::createOne();
        $product = ProductFactory::createOne(['shop' => $producer->getShop(), 'name' => 'Pain aux noix']);
        $client->loginUser($producer, 'producer');

        $client->request('POST', '/pro/produits/'.$product->getId().'/archiver', ['_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', '/pro/produits/'.$product->getId().'/archiver');
        self::assertResponseStatusCodeSame(405);

        self::assertFalse($this->product('Pain aux noix')->isArchived());
    }

    public function testAnotherProducerCannotSeeEditOrArchiveAProduct(): void
    {
        $client = self::createClient();
        $owner = UserFactory::createOne();
        $product = ProductFactory::createOne(['shop' => $owner->getShop(), 'name' => 'Pain de campagne', 'priceCents' => 520]);
        $client->loginUser(UserFactory::createOne(), 'producer');
        $id = $product->getId();

        $client->request('GET', '/pro/produits');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('main', 'Pain de campagne');

        $client->request('GET', '/pro/produits/'.$id.'/modifier');
        self::assertResponseStatusCodeSame(404);

        $client->request('POST', '/pro/produits/'.$id.'/modifier', ['product' => ['name' => 'Piraté', 'priceCents' => '0,01']]);
        self::assertResponseStatusCodeSame(404);

        $client->request('POST', '/pro/produits/'.$id.'/archiver', ['_token' => 'whatever']);
        self::assertResponseStatusCodeSame(404);

        $untouched = $this->product('Pain de campagne');
        self::assertSame(520, $untouched->getPriceCents());
        self::assertFalse($untouched->isArchived());
    }

    public function testProductsMoveUpAndDownWithinTheirGroup(): void
    {
        $client = self::createClient();
        $producer = UserFactory::createOne();
        $shop = $producer->getShop();
        ProductFactory::createOne(['shop' => $shop, 'name' => 'Baguette', 'position' => 1]);
        ProductFactory::createOne(['shop' => $shop, 'name' => 'Fougasse', 'position' => 2, 'kind' => ProductKind::Seasonal]);
        ProductFactory::createOne(['shop' => $shop, 'name' => 'Pain de campagne', 'position' => 3]);
        ProductFactory::createOne(['shop' => $shop, 'name' => 'Brioche', 'position' => 3]);
        $client->loginUser($producer, 'producer');

        $crawler = $client->request('GET', '/pro/produits');
        // The first of a group cannot go up. Same position: by name, so Brioche comes before Pain de campagne.
        self::assertSelectorExists(\sprintf('[data-product="%d"] button[value="up"][disabled]', $this->product('Baguette')->getId()));
        $form = $crawler->filter(\sprintf('[data-product="%d"] form[data-product-move]', $this->product('Pain de campagne')->getId()));
        $client->submit($form->filter('button[value="up"]')->form());

        self::assertResponseRedirects('/pro/produits', 303);
        $crawler = $client->followRedirect();
        self::assertSame(
            ['Baguette', 'Pain de campagne', 'Brioche'],
            $crawler->filter('[data-section="permanent"] [data-product] p.truncate')->each(static fn ($node): string => $node->text()),
        );
        // Positions are distinct again, and the seasonal product kept its place among its own.
        self::assertSame(1, $this->product('Baguette')->getPosition());
        self::assertSame(3, $this->product('Pain de campagne')->getPosition());
        self::assertSame(2, $this->product('Fougasse')->getPosition());
        self::assertSame(4, $this->product('Brioche')->getPosition());
    }

    public function testMovingRequiresAValidCsrfTokenAndStaysInTheShop(): void
    {
        $client = self::createClient();
        $owner = UserFactory::createOne();
        $first = ProductFactory::createOne(['shop' => $owner->getShop(), 'name' => 'Baguette', 'position' => 1]);
        $second = ProductFactory::createOne(['shop' => $owner->getShop(), 'name' => 'Brioche', 'position' => 2]);

        $client->loginUser($owner, 'producer');
        $client->request('POST', '/pro/produits/'.$second->getId().'/deplacer', ['_token' => 'forged', 'direction' => 'up']);
        self::assertResponseStatusCodeSame(403);

        $client->loginUser(UserFactory::createOne(), 'producer');
        $client->request('POST', '/pro/produits/'.$second->getId().'/deplacer', ['_token' => 'whatever', 'direction' => 'up']);
        self::assertResponseStatusCodeSame(404);

        self::assertSame(1, $this->product('Baguette')->getPosition());
        self::assertSame(2, $this->product('Brioche')->getPosition());
        self::assertNotSame($first->getId(), $second->getId());
    }

    public function testUnknownProductIsNotFound(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(), 'producer');

        $client->request('GET', '/pro/produits/999999/modifier');
        self::assertResponseStatusCodeSame(404);
    }

    public function testUploadsReplacesAndRemovesThePhoto(): void
    {
        $client = self::createClient();
        $producer = UserFactory::createOne();
        $client->loginUser($producer, 'producer');

        $client->request('GET', '/pro/produits/nouveau');
        $form = $client->getCrawler()->selectButton('Enregistrer')->form(['product[name]' => 'Pain de campagne', 'product[priceCents]' => '5,20']);
        $this->attachFile($form, 'product[photo]', $this->pngFile());
        $client->submit($form);
        self::assertResponseRedirects('/pro/produits');

        $product = $this->product('Pain de campagne');
        $firstPhoto = $product->getPhotoPath();
        self::assertNotNull($firstPhoto);
        self::assertMatchesRegularExpression('#^uploads/products/'.$producer->getShop()?->getId().'/[a-f0-9]{32}\.png$#', $firstPhoto);
        self::assertTrue(self::storedFileExists($firstPhoto));

        // The list shows the photo.
        $client->followRedirect();
        self::assertSelectorExists('[data-product] img[src$="'.$firstPhoto.'"]');

        // Replacing the photo deletes the previous file.
        $client->request('GET', '/pro/produits/'.$product->getId().'/modifier');
        self::assertSelectorExists('[data-current-photo]');
        $form = $client->getCrawler()->selectButton('Enregistrer')->form();
        $this->attachFile($form, 'product[photo]', $this->pngFile('autre.png'));
        $client->submit($form);
        self::assertResponseRedirects('/pro/produits');

        $secondPhoto = $this->product('Pain de campagne')->getPhotoPath();
        self::assertNotNull($secondPhoto);
        self::assertNotSame($firstPhoto, $secondPhoto);
        self::assertTrue(self::storedFileExists($secondPhoto));
        self::assertFalse(self::storedFileExists($firstPhoto));

        // Removing it.
        $client->request('GET', '/pro/produits/'.$product->getId().'/modifier');
        $form = $client->getCrawler()->selectButton('Enregistrer')->form();
        $this->setCheckbox($form, 'product[removePhoto]', true);
        $client->submit($form);
        self::assertResponseRedirects('/pro/produits');

        self::assertNull($this->product('Pain de campagne')->getPhotoPath());
        self::assertFalse(self::storedFileExists($secondPhoto));
    }

    public function testAFileThatIsNotAnImageIsRejected(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(), 'producer');

        $client->request('GET', '/pro/produits/nouveau');
        $form = $client->getCrawler()->selectButton('Enregistrer')->form(['product[name]' => 'Pain de campagne', 'product[priceCents]' => '5,20']);
        $this->attachFile($form, 'product[photo]', $this->fakeImageFile());
        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Choisis une image JPEG, PNG ou WebP.', (string) $client->getResponse()->getContent());
        self::assertSame(0, self::getContainer()->get(ProductRepository::class)->count([]));
        self::assertDirectoryDoesNotExist(self::uploadPublicDir().'/uploads');
    }

    public function testAnOversizedPhotoIsRejected(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(), 'producer');

        $client->request('GET', '/pro/produits/nouveau');
        $form = $client->getCrawler()->selectButton('Enregistrer')->form(['product[name]' => 'Pain de campagne', 'product[priceCents]' => '5,20']);
        $this->attachFile($form, 'product[photo]', $this->oversizedPngFile());
        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('[role="alert"]');
        self::assertSame(0, self::getContainer()->get(ProductRepository::class)->count([]));
        self::assertDirectoryDoesNotExist(self::uploadPublicDir().'/uploads');
    }

    private function product(string $name): Product
    {
        $product = self::getContainer()->get(ProductRepository::class)->findOneBy(['name' => $name]);
        self::assertNotNull($product);

        return $product;
    }
}
