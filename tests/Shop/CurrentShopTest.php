<?php

declare(strict_types=1);

namespace App\Tests\Shop;

use App\Shop\Exception\NoCurrentShopException;
use App\Shop\Service\CurrentShop;
use App\Tests\Factory\CustomerFactory;
use App\Tests\Factory\ShopFactory;
use App\Tests\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\UserInterface;
use Zenstruck\Foundry\Test\Factories;

final class CurrentShopTest extends KernelTestCase
{
    use Factories;

    public function testReturnsTheShopOfTheLoggedInProducer(): void
    {
        $producer = UserFactory::createOne();
        UserFactory::createOne();
        $this->logIn($producer, 'producer');

        $currentShop = self::getContainer()->get(CurrentShop::class);

        self::assertSame($producer->getShop(), $currentShop->get());
        self::assertSame($producer->getShop(), $currentShop->find());
        self::assertTrue($currentShop->has());
        self::assertSame($producer, $currentShop->getUser());
    }

    public function testAProducerOnlyOwnsTheirOwnShop(): void
    {
        $producer = UserFactory::createOne();
        $otherShop = ShopFactory::createOne();
        $this->logIn($producer, 'producer');

        $currentShop = self::getContainer()->get(CurrentShop::class);

        self::assertTrue($currentShop->owns($producer->getShop()));
        self::assertFalse($currentShop->owns($otherShop));
        self::assertFalse($currentShop->owns(null));

        $currentShop->denyUnlessOwns($producer->getShop());

        $this->expectException(NotFoundHttpException::class);
        $currentShop->denyUnlessOwns($otherShop);
    }

    public function testThrowsWhenNobodyIsLoggedIn(): void
    {
        $currentShop = self::getContainer()->get(CurrentShop::class);

        self::assertNull($currentShop->find());
        self::assertFalse($currentShop->has());
        self::assertFalse($currentShop->owns(ShopFactory::createOne()));

        $this->expectException(NoCurrentShopException::class);
        $currentShop->get();
    }

    public function testThrowsWhenTheProducerHasNoShopYet(): void
    {
        $this->logIn(UserFactory::new()->withoutShop()->create(), 'producer');

        $this->expectException(NoCurrentShopException::class);
        self::getContainer()->get(CurrentShop::class)->get();
    }

    public function testACustomerNeverHasACurrentShop(): void
    {
        ShopFactory::createOne();
        $this->logIn(CustomerFactory::createOne(), 'main');

        $currentShop = self::getContainer()->get(CurrentShop::class);
        self::assertNull($currentShop->getUser());

        $this->expectException(NoCurrentShopException::class);
        $currentShop->get();
    }

    private function logIn(UserInterface $user, string $firewall): void
    {
        self::getContainer()->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($user, $firewall, $user->getRoles()));
    }
}
