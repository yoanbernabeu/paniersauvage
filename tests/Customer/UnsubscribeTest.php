<?php

declare(strict_types=1);

namespace App\Tests\Customer;

use App\Customer\Entity\ShopFollower;
use App\Notification\Service\NotificationLinks;
use App\Tests\Factory\ShopFollowerFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

final class UnsubscribeTest extends WebTestCase
{
    use Factories;

    public function testTheSignedLinkAsksForConfirmationThenStopsTheAnnouncements(): void
    {
        $client = self::createClient();
        $follower = ShopFollowerFactory::createOne();
        $path = $this->pathOf($follower);

        $crawler = $client->request('GET', $path);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Ne plus recevoir les annonces de '.$follower->getShop()->getName());
        // A GET never changes anything (link scanners open every link of an e-mail).
        self::assertTrue($this->reload($follower)->hasEmailConsent());

        $client->submit($crawler->filter('form[method="post"]')->form());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-unsubscribed]');
        $follower = $this->reload($follower);
        self::assertFalse($follower->hasEmailConsent());
        // Still following the shop: only the e-mails stop.
        self::assertNotNull($follower->getId());
    }

    public function testMailClientsCanUnsubscribeInOneClick(): void
    {
        $client = self::createClient();
        $follower = ShopFollowerFactory::createOne();

        $client->request('POST', $this->pathOf($follower), ['List-Unsubscribe' => 'One-Click']);

        self::assertResponseIsSuccessful();
        self::assertFalse($this->reload($follower)->hasEmailConsent());
    }

    public function testATamperedLinkIsRefused(): void
    {
        $client = self::createClient();
        $follower = ShopFollowerFactory::createOne();
        $other = ShopFollowerFactory::createOne();

        // Signature of one customer, id of another one.
        $path = $this->pathOf($follower);
        $forged = str_replace('/'.$follower->getCustomer()->getId().'?', '/'.$other->getCustomer()->getId().'?', $path);

        $client->request('POST', $forged);
        self::assertResponseStatusCodeSame(404);

        $client->request('POST', strtok($path, '?') ?: $path);
        self::assertResponseStatusCodeSame(404);

        self::assertTrue($this->reload($other)->hasEmailConsent());
        self::assertTrue($this->reload($follower)->hasEmailConsent());
    }

    public function testAnAlreadyUnsubscribedCustomerIsToldSo(): void
    {
        $client = self::createClient();
        $follower = ShopFollowerFactory::createOne(['emailConsent' => false]);

        $client->request('GET', $this->pathOf($follower));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-unsubscribe-already-off]');
    }

    private function pathOf(ShopFollower $follower): string
    {
        $url = self::getContainer()->get(NotificationLinks::class)->unsubscribe($follower->getShop(), $follower->getCustomer());
        self::assertStringStartsWith('http://localhost:8000/e-mails/ne-plus-recevoir/', $url);

        // Absolute: the signature covers the host the e-mail links to.
        return $url;
    }

    private function reload(ShopFollower $follower): ShopFollower
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $fresh = $em->find(ShopFollower::class, $follower->getId());
        self::assertNotNull($fresh);

        return $fresh;
    }
}
