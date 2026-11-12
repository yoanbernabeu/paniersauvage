<?php

declare(strict_types=1);

namespace App\Tests\Legal;

use App\Legal\Enum\ReportReason;
use App\Shop\Entity\Shop;
use App\Tests\Factory\ShopFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;
use Twig\Environment;
use Zenstruck\Foundry\Test\Factories;

/**
 * Report of a shop by any visitor, e-mailed to the operator (LEGAL_CONTACT_EMAIL).
 */
final class ShopReportTest extends WebTestCase
{
    use Factories;

    public function testAVisitorReportsAShopAndTheOperatorIsTold(): void
    {
        $client = self::createClient();
        $shop = ShopFactory::createOne(['name' => 'Ferme Douteuse', 'slug' => 'ferme-douteuse', 'siret' => '82345678900010']);

        $crawler = $client->request('GET', '/ferme-douteuse/signaler');
        self::assertResponseIsSuccessful();
        self::assertRouteSame('shop_report');
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex');
        self::assertSelectorTextContains('h1', 'Signaler cette boutique');
        self::assertCount(\count(ReportReason::cases()), $crawler->filter('input[type="radio"][name="shop_report[reason]"]'));
        self::assertSelectorExists('input[name="shop_report[_token]"]');

        $client->submitForm('Envoyer le signalement', [
            'shop_report[reason]' => ReportReason::NotAProducer->value,
            'shop_report[message]' => "  Ils revendent des légumes du marché de gros.\nVu samedi.  ",
            'shop_report[email]' => '  Voisin@Example.test ',
        ]);

        self::assertResponseRedirects('/ferme-douteuse', 303);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertEmailAddressContains($email, 'To', 'contact@panier-sauvage.test');
        self::assertEmailAddressContains($email, 'Reply-To', 'voisin@example.test');
        self::assertEmailSubjectContains($email, 'Signalement : Ferme Douteuse');
        self::assertEmailHtmlBodyContains($email, 'revendent des légumes');
        self::assertEmailTextBodyContains($email, ReportReason::NotAProducer->label());
        self::assertEmailHtmlBodyContains($email, '82345678900010');
        self::assertEmailTextBodyContains($email, "Ils revendent des légumes du marché de gros.\nVu samedi.");
        self::assertEmailTextBodyContains($email, 'voisin@example.test');

        $client->followRedirect();
        self::assertSelectorTextContains('[data-flash="success"]', 'ton signalement est bien parti');
        self::assertSame('ferme-douteuse', $shop->getSlug());
    }

    public function testTheEmailIsOptional(): void
    {
        $client = self::createClient();
        $this->report($client, $this->shop(), ['shop_report[email]' => '']);

        self::assertResponseRedirects();
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame([], $email->getReplyTo());
        self::assertEmailTextBodyContains($email, 'n\'a pas laissé d\'e-mail');
        self::assertEmailTextBodyContains($email, 'non renseigné');
    }

    public function testInvalidReportsAreShownAgainInFrench(): void
    {
        $client = self::createClient();
        $shop = $this->shop();
        $client->request('GET', '/'.$shop->getSlug().'/signaler');

        $client->submitForm('Envoyer le signalement', ['shop_report[message]' => 'Court', 'shop_report[email]' => 'pas-un-email']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-report-form]', 'Choisis un motif.');
        self::assertSelectorTextContains('[data-report-form]', '10 caractères minimum');
        self::assertSelectorTextContains('[data-report-form]', 'Cette adresse e-mail ne semble pas valide.');
        self::assertQueuedEmailCount(0);
    }

    public function testBotsFillingTheHoneypotAreDroppedSilently(): void
    {
        $client = self::createClient();
        $shop = $this->shop();
        $this->report($client, $shop, ['shop_report[website]' => 'https://spam.example']);

        self::assertResponseRedirects('/'.$shop->getSlug(), 303);
        self::assertQueuedEmailCount(0);
        $client->followRedirect();
        self::assertSelectorTextContains('[data-flash="success"]', 'ton signalement est bien parti');
    }

    public function testABlankHoneypotIsAHuman(): void
    {
        $client = self::createClient();
        $this->report($client, $this->shop(), ['shop_report[website]' => '   ']);

        self::assertQueuedEmailCount(1);
    }

    public function testTheFormIsLabelledAndKeepsItsLimits(): void
    {
        $client = self::createClient();
        $shop = $this->shop();
        $crawler = $client->request('GET', '/'.$shop->getSlug().'/signaler');

        self::assertSelectorTextContains('[data-report-form] legend, [data-report-form] label', 'Motif');
        self::assertSelectorTextContains('label[for="shop_report_message"]', 'Ce qui ne va pas');
        self::assertSelectorTextContains('label[for="shop_report_email"]', 'Ton e-mail (facultatif)');
        self::assertSelectorTextContains('label[for="shop_report_website"]', 'Ne remplis pas ce champ');
        self::assertSame('5', $crawler->filter('#shop_report_message')->attr('rows'));
        self::assertNull($crawler->filter('#shop_report_email')->attr('required'));
        self::assertSame('email', $crawler->filter('#shop_report_email')->attr('autocomplete'));
        self::assertNull($crawler->filter('#shop_report_website')->attr('required'));
        self::assertSame('-1', $crawler->filter('#shop_report_website')->attr('tabindex'));

        $this->report($client, $shop, ['shop_report[message]' => str_repeat('a', 2001), 'shop_report[email]' => str_repeat('a', 169).'@example.test']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-report-form]', '2000 caractères maximum');
        self::assertSelectorTextContains('[data-report-form]', '180 caractères');

        $this->report($client, $shop, ['shop_report[message]' => str_repeat('a', 2000), 'shop_report[email]' => str_repeat('a', 167).'@example.test']);
        self::assertResponseRedirects();

        $this->report($client, $shop, ['shop_report[message]' => '   ']);
        self::assertSelectorTextContains('[data-report-form]', 'Explique-nous ce qui ne va pas.');
    }

    public function testReportsAreLimitedPerIp(): void
    {
        $client = self::createClient();
        // The counters live in memory: keep the same kernel for every request.
        $client->disableReboot();
        $shop = $this->shop();

        for ($i = 0; $i < 5; ++$i) {
            $this->report($client, $shop);
            self::assertResponseRedirects();
        }

        $this->report($client, $shop);
        self::assertResponseStatusCodeSame(429);
        self::assertSelectorTextContains('[data-report-form]', 'plusieurs signalements d\'affilée');
        self::assertQueuedEmailCount(0);
    }

    public function testAForgedFormIsRefused(): void
    {
        $client = self::createClient();
        $shop = $this->shop();

        $client->request('POST', '/'.$shop->getSlug().'/signaler', ['shop_report' => [
            'reason' => ReportReason::Other->value,
            'message' => 'Un message assez long pour passer.',
            '_token' => 'forged',
        ]], [], ['HTTP_ORIGIN' => 'https://evil.example']);

        self::assertResponseStatusCodeSame(422);
        self::assertQueuedEmailCount(0);
    }

    public function testAnUnknownShopCannotBeReported(): void
    {
        $client = self::createClient();
        $client->request('GET', '/boutique-inconnue/signaler');

        self::assertResponseStatusCodeSame(404);
    }

    public function testTheReportLinkLeadsToTheForm(): void
    {
        self::bootKernel();
        $shop = $this->shop();

        $html = self::getContainer()->get(Environment::class)->render('shop/_report_link.html.twig', ['shop' => $shop]);

        self::assertStringContainsString(\sprintf('href="/%s/signaler"', $shop->getSlug()), $html);
        self::assertStringContainsString('Signaler cette boutique', $html);
    }

    public function testEveryReasonHasALabel(): void
    {
        foreach (ReportReason::cases() as $reason) {
            self::assertNotSame('', $reason->label());
        }
    }

    private function shop(): Shop
    {
        return ShopFactory::createOne();
    }

    /**
     * @param array<string, string> $fields
     */
    private function report(KernelBrowser $client, Shop $shop, array $fields = []): void
    {
        $client->request('GET', '/'.$shop->getSlug().'/signaler');
        $client->submitForm('Envoyer le signalement', [
            'shop_report[reason]' => ReportReason::Misleading->value,
            'shop_report[message]' => 'Le label bio affiché n\'existe pas.',
            ...$fields,
        ]);
    }
}
