<?php

declare(strict_types=1);

namespace App\Tests\Shop;

use App\Shop\Enum\PaymentMethod;
use App\Shop\Enum\ShopCategory;
use App\Shop\Repository\ShopRepository;
use App\Tests\Factory\DeliveryPointFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Factory\SaleFactory;
use App\Tests\Factory\SaleScheduleFactory;
use App\Tests\Factory\ShopFactory;
use App\Tests\Factory\ShopFollowerFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

final class OnboardingTest extends WebTestCase
{
    use Factories;

    public function testRequiresAuthentication(): void
    {
        $client = self::createClient();
        $client->request('GET', '/pro/bienvenue');

        self::assertResponseRedirects('/pro/connexion');
    }

    public function testAProducerWithoutShopIsDenied(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::new()->withoutShop()->create(), 'producer');
        $client->request('GET', '/pro/bienvenue');

        self::assertResponseStatusCodeSame(403);
    }

    public function testANewShopHasEverythingLeftToDo(): void
    {
        $client = self::createClient();
        $shop = ShopFactory::createOne(['slug' => 'fournil-du-pont', 'description' => null, 'paymentInfo' => null]);
        $client->loginUser(UserFactory::createOne(['shop' => $shop, 'firstName' => 'Camille']), 'producer');

        $crawler = $client->request('GET', '/pro/bienvenue');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Bienvenue, Camille');
        self::assertSame(
            ['shop' => 'no', 'product' => 'no', 'delivery_point' => 'no', 'sale' => 'no', 'share' => 'no', 'directory' => 'no'],
            $this->steps($crawler),
        );
        self::assertSelectorTextContains('main', '0 sur 5');

        // Links to this feature set, and safe fallbacks for the routes of the others.
        self::assertSelectorExists('[data-step="shop"] a[href="/pro/boutique"]');
        self::assertSelectorExists('[data-step="product"] a[href="/pro/produits/nouveau"]');
        self::assertSelectorExists('[data-step="delivery_point"] a[href="/pro/points-de-livraison/nouveau"]');
        self::assertSelectorExists('[data-step="sale"] a[href="/pro/ventes/nouvelle"]');
        self::assertSelectorExists('[data-step="sale"] a[href="/pro/recurrences/nouvelle"]');

        self::assertSelectorTextSame('[data-step="share"] [data-public-url]', 'http://localhost/fournil-du-pont');
        $whatsApp = $crawler->filter('[data-step="share"] a[href^="https://wa.me/?text="]');
        self::assertCount(1, $whatsApp);
        self::assertStringContainsString(rawurlencode('http://localhost/fournil-du-pont'), (string) $whatsApp->attr('href'));
    }

    public function testStepsAreTickedFromTheRealState(): void
    {
        $client = self::createClient();
        $shop = ShopFactory::createOne(['description' => 'Pains au levain cuits au feu de bois.', 'paymentInfo' => 'Espèces, Wero']);
        $client->loginUser(UserFactory::createOne(['shop' => $shop]), 'producer');

        self::assertSame(
            ['shop' => 'yes', 'product' => 'no', 'delivery_point' => 'no', 'sale' => 'no', 'share' => 'no', 'directory' => 'no'],
            $this->steps($client->request('GET', '/pro/bienvenue')),
        );

        ProductFactory::createOne(['shop' => $shop]);
        DeliveryPointFactory::createOne(['shop' => $shop]);

        self::assertSame(
            ['shop' => 'yes', 'product' => 'yes', 'delivery_point' => 'yes', 'sale' => 'no', 'share' => 'no', 'directory' => 'no'],
            $this->steps($client->request('GET', '/pro/bienvenue')),
        );

        SaleScheduleFactory::createOne(['shop' => $shop]);
        ShopFollowerFactory::createOne(['shop' => $shop]);

        $crawler = $client->request('GET', '/pro/bienvenue');
        self::assertSame(
            ['shop' => 'yes', 'product' => 'yes', 'delivery_point' => 'yes', 'sale' => 'yes', 'share' => 'yes', 'directory' => 'no'],
            $this->steps($crawler),
        );
        self::assertSelectorTextContains('main', 'Tout est prêt');
    }

    public function testASaleAloneTicksTheSaleStep(): void
    {
        $client = self::createClient();
        $producer = UserFactory::createOne();
        SaleFactory::createOne(['shop' => $producer->getShop()]);
        $client->loginUser($producer, 'producer');

        self::assertSame('yes', $this->steps($client->request('GET', '/pro/bienvenue'))['sale']);
    }

    public function testArchivedItemsAndOtherShopsDoNotCount(): void
    {
        $client = self::createClient();
        $shop = ShopFactory::createOne(['description' => null]);
        $client->loginUser(UserFactory::createOne(['shop' => $shop]), 'producer');

        // Archived items of this shop.
        ProductFactory::new()->archived()->create(['shop' => $shop]);
        DeliveryPointFactory::createOne(['shop' => $shop, 'archived' => true]);

        // A fully set-up shop belonging to somebody else.
        $otherShop = ShopFactory::createOne(['description' => 'Une autre boutique']);
        ProductFactory::createOne(['shop' => $otherShop]);
        DeliveryPointFactory::createOne(['shop' => $otherShop]);
        SaleFactory::createOne(['shop' => $otherShop]);
        SaleScheduleFactory::createOne(['shop' => $otherShop]);
        ShopFollowerFactory::createOne(['shop' => $otherShop]);

        self::assertSame(
            ['shop' => 'no', 'product' => 'no', 'delivery_point' => 'no', 'sale' => 'no', 'share' => 'no', 'directory' => 'no'],
            $this->steps($client->request('GET', '/pro/bienvenue')),
        );
    }

    public function testTheDashboardShowsTheProgressUntilEverythingIsDone(): void
    {
        $client = self::createClient();
        $shop = ShopFactory::createOne(['description' => 'Pains au levain.', 'paymentInfo' => 'Espèces']);
        ProductFactory::createOne(['shop' => $shop]);
        $client->loginUser(UserFactory::createOne(['shop' => $shop]), 'producer');

        $client->request('GET', '/pro');

        self::assertSelectorTextSame('[data-onboarding-progress] [data-onboarding-count]', '2/5');
        self::assertSelectorExists('[data-onboarding-progress] progress[value="2"][max="5"]');
        self::assertSelectorExists('[data-onboarding-progress] [data-onboarding-next="delivery_point"]');
        self::assertSelectorExists('[data-onboarding-progress] a[href="/pro/points-de-livraison/nouveau"]');

        DeliveryPointFactory::createOne(['shop' => $shop]);
        SaleScheduleFactory::createOne(['shop' => $shop]);
        ShopFollowerFactory::createOne(['shop' => $shop]);

        $client->request('GET', '/pro');
        self::assertSelectorNotExists('[data-onboarding-progress]');
    }

    public function testTheNextStepIsSuggestedAfterEachCreation(): void
    {
        $client = self::createClient();
        $shop = ShopFactory::createOne(['description' => 'Pains au levain.', 'paymentInfo' => 'Espèces']);
        $client->loginUser(UserFactory::createOne(['shop' => $shop]), 'producer');

        $client->request('GET', '/pro/produits/nouveau');
        $client->submitForm('Enregistrer', ['product[name]' => 'Pain de campagne', 'product[priceCents]' => '4,50', 'product[vatRateBp]' => '550', 'product[unit]' => 'piece', 'product[kind]' => 'permanent']);
        $client->followRedirect();

        self::assertSelectorTextContains('[data-flash="success"]', 'Pain de campagne');
        self::assertSelectorTextContains('[data-flashes] [data-onboarding-progress]', 'Étape suivante');
        self::assertSelectorTextSame('[data-flashes] [data-onboarding-count]', '2/5');
        self::assertSelectorExists('[data-flashes] [data-onboarding-next="delivery_point"]');

        // Shown once.
        $client->request('GET', '/pro/produits');
        self::assertSelectorNotExists('[data-onboarding-progress]');
    }

    public function testTheDirectoryIsAnOptionalStepThatNeverBlocksTheProgress(): void
    {
        $client = self::createClient();
        $shop = ShopFactory::createOne(['slug' => 'fournil-du-pont', 'description' => 'Pains au levain.', 'paymentInfo' => null, 'paymentMethods' => [PaymentMethod::Cash]]);
        ProductFactory::createOne(['shop' => $shop]);
        DeliveryPointFactory::createOne(['shop' => $shop]);
        SaleScheduleFactory::createOne(['shop' => $shop]);
        ShopFollowerFactory::createOne(['shop' => $shop]);
        $client->loginUser(UserFactory::createOne(['shop' => $shop]), 'producer');

        $crawler = $client->request('GET', '/pro/bienvenue');

        // The structured means of payment are enough for the first step.
        self::assertSame('yes', $this->steps($crawler)['shop']);
        self::assertSame('no', $this->steps($crawler)['directory']);
        self::assertSelectorTextContains('main', 'Tout est prêt');
        self::assertSelectorTextContains('[data-step="directory"]', 'Facultatif');
        self::assertSelectorExists('[data-step="directory"] a[href="/pro/boutique#settings-visibility"]');

        $client->request('GET', '/pro');
        self::assertSelectorNotExists('[data-onboarding-progress]');

        $managed = self::getContainer()->get(ShopRepository::class)->findOneBySlug('fournil-du-pont');
        self::assertNotNull($managed);
        $managed->setCategories([ShopCategory::Bread])->setListedInSearch(true);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        self::assertSame('yes', $this->steps($client->request('GET', '/pro/bienvenue'))['directory']);
    }

    /**
     * @return array<string, string> step key => "yes" | "no"
     */
    private function steps(Crawler $crawler): array
    {
        $steps = [];
        foreach ($crawler->filter('[data-onboarding] [data-step]') as $node) {
            if ($node instanceof \DOMElement) {
                $steps[$node->getAttribute('data-step')] = $node->getAttribute('data-done');
            }
        }

        return $steps;
    }
}
