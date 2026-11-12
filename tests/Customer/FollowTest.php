<?php

declare(strict_types=1);

namespace App\Tests\Customer;

use App\Customer\Entity\ShopFollower;
use App\Tests\Factory\CustomerFactory;
use App\Tests\Factory\ShopFactory;
use App\Tests\Factory\ShopFollowerFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

final class FollowTest extends WebTestCase
{
    use Factories;
    use MagicLinkTrait;

    public function testSignedInCustomerFollowsAShopWithEmailConsent(): void
    {
        $client = self::createClient();
        $shop = ShopFactory::createOne(['slug' => 'fournil-du-pont', 'name' => 'Le Fournil du Pont']);
        $client->loginUser(CustomerFactory::createOne(['email' => 'camille@example.test']), 'main');

        $this->submitFollow($client, '/fournil-du-pont', consent: true);

        self::assertResponseRedirects('/fournil-du-pont');
        $client->followRedirect();
        self::assertSelectorTextContains('[data-flash="success"]', 'Tu suis maintenant Le Fournil du Pont');
        self::assertSelectorTextContains('[data-section="follow"]', 'Tu suis Le Fournil du Pont');
        self::assertSelectorTextContains('[data-section="follow"] button[type="submit"]', 'Ne plus suivre');

        $follower = $this->follower('camille@example.test');
        self::assertNotNull($follower);
        self::assertSame($shop->getId(), $follower->getShop()->getId());
        self::assertTrue($follower->hasEmailConsent());
    }

    public function testConsentIsNotAssumed(): void
    {
        $client = self::createClient();
        ShopFactory::createOne(['slug' => 'fournil-du-pont']);
        $client->loginUser(CustomerFactory::createOne(['email' => 'camille@example.test']), 'main');

        $crawler = $client->request('GET', '/fournil-du-pont');
        self::assertCount(0, $crawler->filter('input[name="email_consent"][checked]'), 'The consent box is never pre-ticked.');

        $this->submitFollow($client, '/fournil-du-pont', consent: false);

        $follower = $this->follower('camille@example.test');
        self::assertNotNull($follower);
        self::assertFalse($follower->hasEmailConsent());
    }

    public function testCustomerChangesTheEmailSettingFromTheirAccount(): void
    {
        $client = self::createClient();
        $customer = CustomerFactory::createOne(['email' => 'camille@example.test']);
        ShopFollowerFactory::createOne(['customer' => $customer, 'shop' => ShopFactory::createOne(['slug' => 'fournil-du-pont']), 'emailConsent' => true]);
        $client->loginUser($customer, 'main');

        $crawler = $client->request('GET', '/mon-compte');
        $client->submit($crawler->filter('form[action="/fournil-du-pont/suivre"]')->form(), ['email_consent' => false]);

        self::assertResponseRedirects('/mon-compte#producteurs');
        self::assertSame(1, ShopFollowerFactory::repository()->count());

        $follower = $this->follower('camille@example.test');
        self::assertNotNull($follower);
        self::assertFalse($follower->hasEmailConsent());
    }

    public function testCustomerUnfollowsAShop(): void
    {
        $client = self::createClient();
        $customer = CustomerFactory::createOne(['email' => 'camille@example.test']);
        $shop = ShopFactory::createOne(['slug' => 'fournil-du-pont', 'name' => 'Le Fournil du Pont']);
        ShopFollowerFactory::createOne(['customer' => $customer, 'shop' => $shop]);
        ShopFollowerFactory::createOne(['shop' => $shop]);
        $client->loginUser($customer, 'main');

        $crawler = $client->request('GET', '/mon-compte');
        $client->submit($crawler->filter('form[action="/fournil-du-pont/ne-plus-suivre"]')->form());

        self::assertResponseRedirects('/mon-compte#producteurs');
        self::assertNull($this->follower('camille@example.test'));
        self::assertSame(1, ShopFollowerFactory::repository()->count(), 'Other followers are untouched.');

        $client->followRedirect();
        self::assertSelectorTextContains('[data-flash="success"]', 'Tu ne suis plus Le Fournil du Pont');
    }

    public function testAnonymousVisitorSignsInThenTheFollowIsApplied(): void
    {
        $client = self::createClient();
        ShopFactory::createOne(['slug' => 'fournil-du-pont', 'name' => 'Le Fournil du Pont']);

        $this->submitFollow($client, '/fournil-du-pont', consent: true);

        self::assertResponseRedirects('/connexion');
        self::assertSame(0, ShopFollowerFactory::repository()->count());

        $client->followRedirect();
        self::assertSelectorTextContains('[data-flash="info"]', 'Connecte-toi pour suivre Le Fournil du Pont');
        $client->submitForm('Recevoir mon lien', ['login[email]' => 'camille@example.test']);

        // Unknown address: the e-mail holds an account-creation link, the follow survives it.
        $this->confirmLink($client, $this->linkOf($this->lastEmail()));
        self::assertResponseRedirects('/fournil-du-pont');

        $follower = $this->follower('camille@example.test');
        self::assertNotNull($follower);
        self::assertTrue($follower->hasEmailConsent());

        $client->followRedirect();
        self::assertSelectorTextContains('[data-flash="success"]', 'tu suis maintenant Le Fournil du Pont');
        self::assertSelectorTextContains('[data-section="follow"]', 'Tu suis Le Fournil du Pont');
    }

    public function testFollowAndUnfollowRequireACsrfToken(): void
    {
        $client = self::createClient();
        $customer = CustomerFactory::createOne();
        $shop = ShopFactory::createOne(['slug' => 'fournil-du-pont']);
        ShopFollowerFactory::createOne(['customer' => $customer, 'shop' => $shop]);
        $client->loginUser($customer, 'main');

        $client->request('POST', '/fournil-du-pont/ne-plus-suivre');
        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, ShopFollowerFactory::repository()->count());

        $client->request('POST', '/fournil-du-pont/suivre', ['email_consent' => '1', '_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testFollowIsPostOnlyAndUnknownShopAnswers404(): void
    {
        $client = self::createClient();
        ShopFactory::createOne(['slug' => 'fournil-du-pont']);
        $client->loginUser(CustomerFactory::createOne(), 'main');

        $client->request('GET', '/fournil-du-pont/suivre');
        self::assertResponseStatusCodeSame(405);

        $client->request('POST', '/boutique-inconnue/suivre');
        self::assertResponseStatusCodeSame(404);

        $client->request('POST', '/admin/suivre');
        self::assertResponseStatusCodeSame(404);
    }

    private function submitFollow(KernelBrowser $client, string $shopPath, bool $consent): void
    {
        $crawler = $client->request('GET', $shopPath);
        self::assertResponseIsSuccessful();

        $client->submit($crawler->filter(\sprintf('form[action="%s/suivre"]', $shopPath))->form(), ['email_consent' => $consent]);
    }

    private function follower(string $email): ?ShopFollower
    {
        $customer = CustomerFactory::repository()->findOneBy(['email' => $email]);
        self::assertNotNull($customer);

        return ShopFollowerFactory::repository()->findOneBy(['customer' => $customer]);
    }
}
