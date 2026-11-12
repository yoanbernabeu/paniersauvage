<?php

declare(strict_types=1);

namespace App\Tests\Shop;

use App\Shop\Entity\Shop;
use App\Shop\Enum\PaymentMethod;
use App\Shop\Enum\ShopCategory;
use App\Shop\Repository\ShopRepository;
use App\Tests\Factory\ShopFactory;
use App\Tests\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

final class ShopSettingsTest extends WebTestCase
{
    use Factories;
    use UploadTestTrait;

    public function testRequiresAuthentication(): void
    {
        $client = self::createClient();
        $client->request('GET', '/pro/boutique');

        self::assertResponseRedirects('/pro/connexion');
    }

    public function testAProducerWithoutShopIsDenied(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::new()->withoutShop()->create(), 'producer');
        $client->request('GET', '/pro/boutique');

        self::assertResponseStatusCodeSame(403);
    }

    public function testShowsTheShopAndItsPublicLink(): void
    {
        $client = self::createClient();
        $shop = ShopFactory::createOne(['name' => 'Le Fournil du Pont', 'slug' => 'fournil-du-pont', 'paymentInfo' => 'Espèces, chèque']);
        $client->loginUser(UserFactory::createOne(['shop' => $shop]), 'producer');

        $crawler = $client->request('GET', '/pro/boutique');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Ma boutique');
        self::assertInputValueSame('shop_settings[name]', 'Le Fournil du Pont');
        self::assertSelectorTextContains('textarea[name="shop_settings[paymentInfo]"]', 'Espèces, chèque');

        self::assertSelectorTextSame('[data-public-url]', 'http://localhost/fournil-du-pont');
        // The copied link counts as shared by the producer (?via=lien, no cookie).
        self::assertSelectorExists('[data-controller="clipboard"][data-clipboard-text-value="http://localhost/fournil-du-pont?via=lien"] button[data-action="clipboard#copy"]');

        $whatsApp = $crawler->filter('a[href^="https://wa.me/?text="]');
        self::assertCount(1, $whatsApp);
        self::assertSelectorTextContains('a[href^="https://wa.me/?text="]', 'Partager sur WhatsApp');
        self::assertStringContainsString(rawurlencode('http://localhost/fournil-du-pont?via=lien'), (string) $whatsApp->attr('href'));
        self::assertStringContainsString(rawurlencode('Le Fournil du Pont'), (string) $whatsApp->attr('href'));
    }

    public function testUpdatesOnlyTheCurrentShop(): void
    {
        $client = self::createClient();
        $shop = ShopFactory::createOne(['slug' => 'ma-boutique']);
        $otherShop = ShopFactory::createOne(['name' => 'Boutique voisine', 'slug' => 'boutique-voisine', 'paymentInfo' => 'Chèque', 'contactEmail' => 'voisin@example.test']);
        UserFactory::createOne(['shop' => $otherShop]);
        $client->loginUser(UserFactory::createOne(['shop' => $shop]), 'producer');

        $client->request('GET', '/pro/boutique');
        $form = $client->getCrawler()->selectButton('Enregistrer')->form([
            'shop_settings[name]' => 'Le Fournil du Pont',
            'shop_settings[description]' => 'Pains au levain cuits au feu de bois.',
            'shop_settings[siret]' => '123 456 789 00012',
            'shop_settings[address]' => '3 rue du Pont, 12000 Rodez',
            'shop_settings[phone]' => '06 12 34 56 78',
            'shop_settings[paymentInfo]' => 'Wero au 06 12 34 56 78, espèces',
            'shop_settings[legalName]' => '  Fournil du Pont SARL ',
            'shop_settings[contactEmail]' => 'bonjour@fournil.test',
            'shop_settings[localCurrencyName]' => 'la Mesure',
            'shop_settings[foodVoucherDetails]' => ' ',
            'shop_settings[defaultEditCutoffHours]' => '12',
        ]);
        $this->setCheckbox($form, 'shop_settings[allowCustomerEdit]', false);
        $this->setCheckbox($form, 'shop_settings[listedInSearch]', true);
        $this->setCheckbox($form, 'shop_settings[categories][0]', true);
        $this->setCheckbox($form, 'shop_settings[categories][3]', true);
        $this->setCheckbox($form, 'shop_settings[paymentMethods][1]', true);
        $this->setCheckbox($form, 'shop_settings[paymentMethods][5]', true);
        $client->submit($form);

        self::assertResponseRedirects('/pro/boutique');
        $client->followRedirect();
        self::assertSelectorTextContains('[data-flash="success"]', 'Ta boutique est à jour.');

        $updated = $this->shop('ma-boutique');
        self::assertSame('Le Fournil du Pont', $updated->getName());
        self::assertSame('Pains au levain cuits au feu de bois.', $updated->getDescription());
        self::assertSame('12345678900012', $updated->getSiret());
        self::assertSame('3 rue du Pont, 12000 Rodez', $updated->getAddress());
        self::assertSame('06 12 34 56 78', $updated->getPhone());
        self::assertSame('Wero au 06 12 34 56 78, espèces', $updated->getPaymentInfo());
        self::assertSame(12, $updated->getDefaultEditCutoffHours());
        self::assertFalse($updated->isAllowCustomerEdit());
        self::assertTrue($updated->isListedInSearch());
        self::assertSame([ShopCategory::Bread, ShopCategory::Dairy], $updated->getCategories());
        self::assertSame([PaymentMethod::Cheque, PaymentMethod::LocalCurrency], $updated->getPaymentMethods());
        self::assertSame('la Mesure', $updated->getLocalCurrencyName());
        self::assertNull($updated->getFoodVoucherDetails());
        self::assertSame('Fournil du Pont SARL', $updated->getLegalName());
        self::assertSame('bonjour@fournil.test', $updated->getContactEmail());
        // The public address never changes with the name.
        self::assertSame('ma-boutique', $updated->getSlug());

        // Isolation: the neighbour's shop is untouched.
        $neighbour = $this->shop('boutique-voisine');
        self::assertSame('Boutique voisine', $neighbour->getName());
        self::assertSame('Chèque', $neighbour->getPaymentInfo());
        self::assertTrue($neighbour->isAllowCustomerEdit());
        self::assertFalse($neighbour->isListedInSearch());
        self::assertSame([], $neighbour->getCategories());
        self::assertSame('voisin@example.test', $neighbour->getContactEmail());
    }

    public function testTheDirectoryIsOptInAndExplained(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(), 'producer');

        $crawler = $client->request('GET', '/pro/boutique');

        self::assertSelectorTextContains('[data-section="visibility"]', 'Apparaître dans l\'annuaire des producteurs');
        self::assertSelectorTextContains('[data-section="visibility"]', 'seule la distance compte');
        foreach ([
            'legalName' => ['Raison sociale', '[autocomplete="organization"]'],
            'contactEmail' => ['E-mail de contact', '[autocomplete="email"]'],
            'localCurrencyName' => ['Nom de la monnaie locale', '[placeholder="La Mesure"]'],
            'foodVoucherDetails' => ['Chèques ou caisse alimentaire acceptés', '[placeholder="Caisse alimentaire de Dieulefit"]'],
        ] as $field => [$label, $attribute]) {
            self::assertSelectorTextContains(\sprintf('label[for="shop_settings_%s"]', $field), $label);
            self::assertSelectorExists(\sprintf('input[name="shop_settings[%s]"]%s:not([required])', $field, $attribute));
        }
        self::assertCount(0, $crawler->filter('[data-choice-grid] input[required]'));
        self::assertNull($crawler->filter('input[name="shop_settings[listedInSearch]"]')->attr('checked'));
        self::assertCount(\count(ShopCategory::cases()), $crawler->filter('[data-choice-grid="categories"] input[type="checkbox"]'));
        self::assertSelectorTextContains('[data-choice-grid="payment-methods"]', 'Chèques ou caisse alimentaire');
        self::assertSelectorTextNotContains('[data-choice-grid="categories"]', 'facultatif');
    }

    public function testTheDirectoryNeedsACategory(): void
    {
        $client = self::createClient();
        $shop = ShopFactory::createOne(['slug' => 'ma-boutique']);
        $client->loginUser(UserFactory::createOne(['shop' => $shop]), 'producer');

        $client->request('GET', '/pro/boutique');
        $form = $client->getCrawler()->selectButton('Enregistrer')->form();
        $this->setCheckbox($form, 'shop_settings[listedInSearch]', true);
        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-choice-grid="categories"]', 'Choisis au moins une catégorie pour apparaître dans l\'annuaire.');
        self::assertFalse($this->shop('ma-boutique')->isListedInSearch());
    }

    public function testAnEmptyCutoffMeansUntilTheDeadline(): void
    {
        $client = self::createClient();
        $shop = ShopFactory::createOne(['slug' => 'ma-boutique', 'defaultEditCutoffHours' => 24]);
        $client->loginUser(UserFactory::createOne(['shop' => $shop]), 'producer');

        $client->request('GET', '/pro/boutique');
        $client->submitForm('Enregistrer', ['shop_settings[defaultEditCutoffHours]' => '']);

        self::assertResponseRedirects('/pro/boutique');
        self::assertNull($this->shop('ma-boutique')->getDefaultEditCutoffHours());
    }

    public function testInvalidDataIsRejected(): void
    {
        $client = self::createClient();
        $shop = ShopFactory::createOne(['name' => 'Le Fournil du Pont', 'slug' => 'ma-boutique']);
        $client->loginUser(UserFactory::createOne(['shop' => $shop]), 'producer');

        $client->request('GET', '/pro/boutique');
        $client->submitForm('Enregistrer', [
            'shop_settings[name]' => '',
            'shop_settings[siret]' => '1234',
            'shop_settings[defaultEditCutoffHours]' => '-3',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Le SIRET contient 14 chiffres.', (string) $client->getResponse()->getContent());

        $unchanged = $this->shop('ma-boutique');
        self::assertSame('Le Fournil du Pont', $unchanged->getName());
        self::assertNull($unchanged->getSiret());
    }

    public function testUploadsReplacesAndRemovesTheLogo(): void
    {
        $client = self::createClient();
        $shop = ShopFactory::createOne(['slug' => 'ma-boutique']);
        $client->loginUser(UserFactory::createOne(['shop' => $shop]), 'producer');

        $client->request('GET', '/pro/boutique');
        self::assertSelectorNotExists('[data-current-logo]');
        $form = $client->getCrawler()->selectButton('Enregistrer')->form();
        $this->attachFile($form, 'shop_settings[logo]', $this->pngFile('logo.png'));
        $client->submit($form);
        self::assertResponseRedirects('/pro/boutique');

        $firstLogo = $this->shop('ma-boutique')->getLogoPath();
        self::assertNotNull($firstLogo);
        self::assertMatchesRegularExpression('#^uploads/shops/\d+/[a-f0-9]{32}\.png$#', $firstLogo);
        self::assertTrue(self::storedFileExists($firstLogo));

        // Replacing the logo deletes the previous file.
        $client->followRedirect();
        self::assertSelectorExists('[data-current-logo]');
        $form = $client->getCrawler()->selectButton('Enregistrer')->form();
        $this->attachFile($form, 'shop_settings[logo]', $this->pngFile('nouveau-logo.png'));
        $client->submit($form);
        self::assertResponseRedirects('/pro/boutique');

        $secondLogo = $this->shop('ma-boutique')->getLogoPath();
        self::assertNotNull($secondLogo);
        self::assertNotSame($firstLogo, $secondLogo);
        self::assertTrue(self::storedFileExists($secondLogo));
        self::assertFalse(self::storedFileExists($firstLogo));

        // Removing it.
        $client->followRedirect();
        $form = $client->getCrawler()->selectButton('Enregistrer')->form();
        $this->setCheckbox($form, 'shop_settings[removeLogo]', true);
        $client->submit($form);
        self::assertResponseRedirects('/pro/boutique');

        self::assertNull($this->shop('ma-boutique')->getLogoPath());
        self::assertFalse(self::storedFileExists($secondLogo));
    }

    public function testAFileThatIsNotAnImageIsRejected(): void
    {
        $client = self::createClient();
        $shop = ShopFactory::createOne(['slug' => 'ma-boutique']);
        $client->loginUser(UserFactory::createOne(['shop' => $shop]), 'producer');

        $client->request('GET', '/pro/boutique');
        $form = $client->getCrawler()->selectButton('Enregistrer')->form();
        $this->attachFile($form, 'shop_settings[logo]', $this->fakeImageFile());
        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Choisis une image JPEG, PNG ou WebP.', (string) $client->getResponse()->getContent());
        self::assertNull($this->shop('ma-boutique')->getLogoPath());
        self::assertDirectoryDoesNotExist(self::uploadPublicDir().'/uploads');
    }

    public function testAnOversizedLogoIsRejected(): void
    {
        $client = self::createClient();
        $shop = ShopFactory::createOne(['slug' => 'ma-boutique']);
        $client->loginUser(UserFactory::createOne(['shop' => $shop]), 'producer');

        $client->request('GET', '/pro/boutique');
        $form = $client->getCrawler()->selectButton('Enregistrer')->form();
        $this->attachFile($form, 'shop_settings[logo]', $this->oversizedPngFile());
        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('[role="alert"]');
        self::assertNull($this->shop('ma-boutique')->getLogoPath());
        self::assertDirectoryDoesNotExist(self::uploadPublicDir().'/uploads');
    }

    private function shop(string $slug): Shop
    {
        $shop = self::getContainer()->get(ShopRepository::class)->findOneBySlug($slug);
        self::assertNotNull($shop);

        return $shop;
    }
}
