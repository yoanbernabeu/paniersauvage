<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Shared\Navigation\ProNavigation;
use App\Shared\Navigation\SafeRouter;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Twig\Environment;

final class TwigIntegrationTest extends KernelTestCase
{
    public function testMoneyAndDateFilters(): void
    {
        $html = $this->render("{{ 520|money }}|{{ date|date_long }}|{{ time_range(date, date.modify('+1 hour')) }}|{{ 550|vat_rate }}", [
            'date' => new \DateTimeImmutable('2026-11-16 18:00'),
        ]);

        self::assertSame("5,20\u{00A0}€|lundi 16 novembre|18\u{00A0}h – 19\u{00A0}h|5,5\u{00A0}%", $html);
    }

    public function testSafePathFallsBackWhenTheRouteDoesNotExist(): void
    {
        self::assertSame('/fallback|/', $this->render("{{ safe_path('a_route_nobody_created', '/fallback') }}|{{ safe_path('home', '/fallback') }}"));
        self::assertSame('no|yes', $this->render("{{ route_exists('a_route_nobody_created') ? 'yes' : 'no' }}|{{ route_exists('home') ? 'yes' : 'no' }}"));
    }

    public function testProNavigationOnlyListsExistingRoutes(): void
    {
        self::bootKernel();
        $router = self::getContainer()->get(SafeRouter::class);

        foreach (self::getContainer()->get(ProNavigation::class)->items() as $item) {
            self::assertTrue($router->exists($item->route));
            self::assertNotSame('', $item->label);
        }

        self::assertFalse($router->exists('a_route_nobody_created'));
        // A route with a required parameter exists too, even though it cannot be generated without it.
        self::assertTrue($router->exists('pro_sale_show'));
        self::assertNull($router->path('pro_sale_show'));
        self::assertNull($router->path('a_route_nobody_created'));
    }

    public function testProLayoutRendersWhateverRoutesExist(): void
    {
        $html = $this->render(
            "{% extends 'layout/pro.html.twig' %}{% block page_title %}Mes ventes{% endblock %}{% block page_actions %}<a href=\"#\">Nouvelle vente</a>{% endblock %}{% block content %}<p>Contenu</p>{% endblock %}",
            flashes: ['success' => ['C\'est enregistré.']],
        );

        self::assertStringContainsString('<h1', $html);
        self::assertStringContainsString('Mes ventes', $html);
        self::assertStringContainsString('Nouvelle vente', $html);
        self::assertStringContainsString('<p>Contenu</p>', $html);
        self::assertStringContainsString('C&#039;est enregistré.', $html);
        self::assertStringContainsString('data-flash="success"', $html);
    }

    public function testCustomerLayoutRendersWithFlashes(): void
    {
        $html = $this->render(
            "{% extends 'layout/customer.html.twig' %}{% block content %}<p>Bonjour</p>{% endblock %}",
            flashes: ['error' => ['Oups.']],
        );

        self::assertStringContainsString('<p>Bonjour</p>', $html);
        self::assertStringContainsString('data-flash="error"', $html);
        self::assertStringContainsString('Se connecter', $html);
    }

    public function testKitComponentsRender(): void
    {
        $html = $this->render(<<<'TWIG'
            <twig:Button>Commander</twig:Button>
            <twig:Button as="a" href="/x" variant="secondary">Voir</twig:Button>
            <twig:Card><twig:Card:Header><twig:Card:Title>Titre</twig:Card:Title></twig:Card:Header><twig:Card:Content>Corps</twig:Card:Content></twig:Card>
            <twig:Label for="a">Nom</twig:Label><twig:Input id="a" name="a" />
            <twig:Textarea name="b">Texte</twig:Textarea>
            <twig:NativeSelect name="c"><twig:NativeSelect:Option value="1">Un</twig:NativeSelect:Option></twig:NativeSelect>
            <twig:Checkbox name="d" />
            <twig:Badge>Du moment</twig:Badge>
            <twig:Alert variant="success"><twig:Alert:Title>Bravo</twig:Alert:Title></twig:Alert>
            <twig:Table><twig:Table:Body><twig:Table:Row><twig:Table:Cell>Cellule</twig:Table:Cell></twig:Table:Row></twig:Table:Body></twig:Table>
            <twig:Separator />
            <twig:Empty><twig:Empty:Title>Rien ici</twig:Empty:Title></twig:Empty>
            <twig:Logo /><twig:Illustration name="basket" /><twig:Illustration name="wheat" /><twig:Illustration name="herbs" />
            <twig:ux:icon name="lucide:shopping-basket" class="size-5" />
            TWIG);

        self::assertStringContainsString('bg-wheat', $html);
        self::assertStringContainsString('border-sage', $html);
        self::assertStringContainsString('Panier', $html);
        self::assertStringContainsString('<svg', $html);
    }

    /**
     * @param array<string, mixed>        $context
     * @param array<string, list<string>> $flashes
     */
    private function render(string $template, array $context = [], array $flashes = []): string
    {
        self::bootKernel();
        $container = self::getContainer();

        $request = Request::create('/');
        $session = new Session(new MockArraySessionStorage());
        foreach ($flashes as $type => $messages) {
            foreach ($messages as $message) {
                $session->getFlashBag()->add($type, $message);
            }
        }
        $request->setSession($session);
        $request->cookies->set($session->getName(), 'test');
        $container->get(RequestStack::class)->push($request);

        /** @var Environment $twig */
        $twig = $container->get(Environment::class);

        return trim($twig->createTemplate($template)->render($context));
    }
}
