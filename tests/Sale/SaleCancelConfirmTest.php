<?php

declare(strict_types=1);

namespace App\Tests\Sale;

use App\Tests\Factory\CustomerFactory;
use App\Tests\Factory\OrderFactory;
use App\Tests\Factory\SaleDeliveryFactory;
use App\Tests\Factory\SaleFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

final class SaleCancelConfirmTest extends WebTestCase
{
    use Factories;
    use SaleScenario;

    public function testTheConfirmationSaysHowManyCustomersWillBeTold(): void
    {
        $client = self::createClient();
        $producer = self::subscribedProducer();
        $sale = SaleFactory::createOne(['shop' => self::shopOf($producer)]);
        SaleDeliveryFactory::createOne(['sale' => $sale]);
        $alice = CustomerFactory::createOne();
        OrderFactory::createMany(2, ['sale' => $sale, 'customer' => $alice]);
        OrderFactory::createOne(['sale' => $sale]);
        OrderFactory::new()->cancelled()->create(['sale' => $sale]);
        $client->loginUser($producer, 'producer');

        $crawler = $client->request('GET', '/pro/ventes/'.$sale->getId());

        $form = $crawler->filter('form[data-sale-cancel]');
        self::assertStringStartsWith('2 clients seront prévenus par e-mail', (string) $form->attr('data-turbo-confirm'));
        self::assertSame('Annuler la vente', $form->attr('data-confirm-label'));
        self::assertNotNull($form->attr('data-confirm-danger'));
    }

    public function testNobodyToTellWithoutOrders(): void
    {
        $client = self::createClient();
        $producer = self::subscribedProducer();
        $sale = SaleFactory::createOne(['shop' => self::shopOf($producer)]);
        $client->loginUser($producer, 'producer');

        $crawler = $client->request('GET', '/pro/ventes/'.$sale->getId());

        self::assertStringStartsWith('Personne n\'a encore commandé', (string) $crawler->filter('form[data-sale-cancel]')->attr('data-turbo-confirm'));
    }
}
