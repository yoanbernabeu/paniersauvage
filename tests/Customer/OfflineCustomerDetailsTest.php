<?php

declare(strict_types=1);

namespace App\Tests\Customer;

use App\Customer\Entity\Customer;
use App\Customer\Form\Model\OfflineCustomerData;
use App\Customer\Service\OfflineCustomerManager;
use App\Notification\Service\ReminderSender;
use App\Tests\Factory\CustomerFactory;
use App\Tests\Ordering\OrderingFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * Details of the customers without e-mail: validation, matching, flashes, reminders.
 */
final class OfflineCustomerDetailsTest extends WebTestCase
{
    use Factories;
    use OrderingFixtures;

    /**
     * @return iterable<string, array{?string, ?string, ?string, ?string, list<string>}>
     */
    public static function provideDetails(): iterable
    {
        yield 'first name and phone' => ['Gisèle', '', '04 75 41 22 33', null, []];
        yield 'last name and phone' => ['', 'Roux', '04 75 41 22 33', null, []];
        yield 'blank names' => ['', '  ', '04 75 41 22 33', null, ['lastName']];
        yield 'an address instead of a phone' => ['Gisèle', 'Roux', '', 'gisele@example.test', []];
        yield 'neither address nor phone' => ['Gisèle', 'Roux', '', '', ['phone']];
        yield 'a placeholder address' => ['Gisèle', 'Roux', '', 'client-3@compte-supprime.invalid', ['email']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('provideDetails')]
    public function testValidation(?string $firstName, ?string $lastName, ?string $phone, ?string $email, array $expected): void
    {
        self::bootKernel();
        $data = new OfflineCustomerData();
        $data->firstName = $firstName;
        $data->lastName = $lastName;
        $data->phone = $phone;
        $data->email = $email;

        $paths = [];
        foreach (self::getContainer()->get(ValidatorInterface::class)->validate($data) as $violation) {
            $paths[] = $violation->getPropertyPath();
        }

        self::assertSame($expected, $paths);
    }

    public function testNamesAreComparedIgnoringCaseAndSpaces(): void
    {
        self::bootKernel();
        $shop = $this->shopOf($this->createProducer());
        $known = CustomerFactory::new()->withoutEmail($shop)->create(['firstName' => 'Élodie', 'lastName' => 'Chave', 'phone' => '06 20 10 00 04']);
        $manager = self::getContainer()->get(OfflineCustomerManager::class);

        self::assertSame($known->getId(), $manager->resolve($shop, ' élodie ', 'CHAVE ', '0620100004')->getId());
        self::assertNull($manager->resolve($shop, 'Élodie', 'Chave', null)->getId(), 'Without phone: a new customer.');
    }

    public function testTheProducerSeesEachCustomerAndIsToldWhatHappened(): void
    {
        $client = self::createClient();
        $producer = $this->createProducer();
        $shop = $this->shopOf($producer);
        $sale = $this->createSale($shop);
        $bread = $this->createOffer($sale, 'Pain', 520);
        CustomerFactory::new()->withoutEmail($shop)->create(['firstName' => 'Gisèle', 'lastName' => 'Roux']);
        $paul = CustomerFactory::new()->withoutEmail($shop)->create(['firstName' => 'Paul', 'lastName' => 'Roux', 'phone' => '06 00 00 00 02']);
        $this->placeOrder($sale, $paul, [self::idOf($bread) => 1]);
        $client->loginUser($producer, 'producer');
        $url = \sprintf('/pro/clients-sans-email/%d', $paul->getId());

        $crawler = $client->request('GET', $url);
        self::assertSelectorTextContains('h1', 'Paul Roux');
        foreach (['firstName' => 'Prénom', 'lastName' => 'Nom', 'phone' => 'Téléphone', 'email' => 'E-mail'] as $field => $label) {
            $input = $crawler->filter(\sprintf('input[name="offline_customer[%s]"]', $field));
            self::assertSame('off', $input->attr('autocomplete'), $field);
            self::assertStringStartsWith($label, trim($crawler->filter(\sprintf('label[for="%s"]', $input->attr('id')))->text()));
        }
        self::assertNull($crawler->filter('input[name="offline_customer[email]"]')->attr('required'));
        self::assertSame('tel', $crawler->filter('input[name="offline_customer[phone]"]')->attr('inputmode'));
        self::assertSame('email', $crawler->filter('input[name="offline_customer[email]"]')->attr('inputmode'));

        $client->submitForm('Enregistrer', ['offline_customer[firstName]' => 'Paulo']);
        $client->followRedirect();
        self::assertSelectorTextContains('[data-flash="success"]', 'Coordonnées de Paulo Roux enregistrées.');

        $client->submitForm('Enregistrer', ['offline_customer[email]' => 'paulo@example.test']);
        $client->followRedirect();
        self::assertSelectorTextContains('[data-flash="success"]', 'Paulo Roux a maintenant un compte : il recevra les prochains e-mails.');
    }

    public function testErasingSaysItIsDone(): void
    {
        $client = self::createClient();
        $producer = $this->createProducer();
        $customer = CustomerFactory::new()->withoutEmail($this->shopOf($producer))->create(['firstName' => 'Gisèle']);
        $client->loginUser($producer, 'producer');

        $client->request('GET', \sprintf('/pro/clients-sans-email/%d', $customer->getId()));
        $client->submitForm('Effacer ses données');

        self::assertResponseRedirects('/pro/ventes', 303);
        $client->followRedirect();
        self::assertSelectorTextContains('[data-flash="success"]', 'Les données personnelles de ce client sont effacées.');
    }

    public function testTheReminderGoesToEveryCustomerWithAnAddress(): void
    {
        self::bootKernel();
        $shop = $this->shopOf($this->createProducer());
        $sale = $this->createSale($shop, ['deliveryDate' => new \DateTimeImmutable('tomorrow')]);
        $bread = $this->createOffer($sale, 'Pain', 520);
        // The customer without e-mail comes first: the others are still reminded.
        $this->placeOrder($sale, CustomerFactory::new()->withoutEmail($shop)->create(), [self::idOf($bread) => 1]);
        $this->placeOrder($sale, $this->createCustomer('lea@example.test'), [self::idOf($bread) => 1]);
        $this->placeOrder($sale, $this->createCustomer('paul@example.test'), [self::idOf($bread) => 1]);

        self::assertSame(2, self::getContainer()->get(ReminderSender::class)->sendForTomorrow());
    }

    public function testOnlyARealAddressCanBeWrittenTo(): void
    {
        self::assertFalse(new Customer('')->hasEmail());
        self::assertTrue(new Customer('lea@example.test')->hasEmail());
        self::assertFalse(new Customer(Customer::anonymizedEmailFor(3))->hasEmail());
    }
}
