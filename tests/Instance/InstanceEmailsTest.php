<?php

declare(strict_types=1);

namespace App\Tests\Instance;

use App\Ordering\Event\OrderPlaced;
use App\Shared\Enum\Actor;
use App\Tests\Notification\NotificationScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * E-mails of a territorial instance: its name as sender and in the frame, its accent, the AGPL
 * credit with the link to its code; the defaults keep Panier Sauvage's e-mails as they are.
 */
final class InstanceEmailsTest extends KernelTestCase
{
    use Factories;
    use InstanceTrait;
    use NotificationScenario;

    public function testPanierSauvageEmailsAreUnchanged(): void
    {
        self::bootKernel();
        $this->dispatch(new OrderPlaced($this->createOrder($this->createSale($this->createShop())), Actor::Customer));

        $email = $this->onlyEmail();
        self::assertSame('Fournil du Pont via Panier Sauvage', $email->getFrom()[0]->getName());
        self::assertEmailHtmlBodyContains($email, 'Panier <span style="color:#2F4337;border-bottom:3px solid #E8B931;">Sauvage</span>');
        self::assertEmailHtmlBodyContains($email, 'Panier Sauvage · les précommandes des producteurs près de chez toi.');
        self::assertEmailHtmlBodyContains($email, 'color:#46604F;text-decoration:underline;');
        self::assertEmailHtmlBodyNotContains($email, 'data-powered-by');
        self::assertEmailHtmlBodyNotContains($email, 'Propulsé par');
        self::assertEmailTextBodyNotContains($email, 'Propulsé par');
    }

    public function testATerritorialInstanceSignsItsEmails(): void
    {
        $this->useTerritorialInstance();
        self::bootKernel();
        $this->dispatch(new OrderPlaced($this->createOrder($this->createSale($this->createShop())), Actor::Customer));

        $email = $this->onlyEmail();
        self::assertSame('Fournil du Pont via Panier Toqué du local', $email->getFrom()[0]->getName());
        self::assertEmailHtmlBodyContains($email, '<span style="color:#26404B;border-bottom:3px solid #E8B931;">Panier Toqué du local</span>');
        self::assertEmailHtmlBodyContains($email, 'color:#3B5F6D;text-decoration:underline;');
        self::assertEmailHtmlBodyContains($email, 'Panier Toqué du local · les précommandes des producteurs près de chez toi.');
        self::assertEmailHtmlBodyContains($email, 'Un service porté par Valence Romans Agglo. Propulsé par <a href="https://forge.example.org/agglo/panier-toque"');
        self::assertEmailHtmlBodyContains($email, '>Panier Sauvage</a>, logiciel libre (AGPL).');
        self::assertEmailTextBodyContains($email, 'Panier Toqué du local · les précommandes des producteurs près de chez toi.');
        self::assertEmailTextBodyContains($email, 'Un service porté par Valence Romans Agglo. Propulsé par Panier Sauvage, logiciel libre (AGPL) : https://forge.example.org/agglo/panier-toque');
    }

    public function testARenamedInstanceWithoutCarrierIsCreditedAlone(): void
    {
        $this->useTerritorialInstance(['INSTANCE_CARRIER_NAME' => '']);
        self::bootKernel();
        $this->dispatch(new OrderPlaced($this->createOrder($this->createSale($this->createShop())), Actor::Customer));

        $email = $this->onlyEmail();
        self::assertEmailHtmlBodyNotContains($email, 'porté par');
        self::assertEmailTextBodyContains($email, "\nPropulsé par Panier Sauvage, logiciel libre (AGPL) : https://forge.example.org/agglo/panier-toque");
    }
}
