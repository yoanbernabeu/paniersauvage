<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ContentSecurityPolicyTest extends WebTestCase
{
    public function testEveryPageHasAStrictPolicy(): void
    {
        $client = self::createClient();
        $client->request('GET', '/connexion');

        self::assertResponseIsSuccessful();
        $policy = (string) $client->getResponse()->headers->get('Content-Security-Policy');
        $directives = $this->directives($policy);

        self::assertSame("'self'", $directives['default-src']);
        self::assertSame("'none'", $directives['object-src']);
        self::assertSame("'self'", $directives['base-uri']);
        self::assertSame("'self'", $directives['frame-ancestors']);
        self::assertSame("'self' https://checkout.stripe.com https://billing.stripe.com", $directives['form-action']);
        self::assertStringNotContainsString('unsafe-inline', $directives['script-src']);
        self::assertStringNotContainsString('unsafe-eval', $directives['script-src']);
        self::assertStringNotContainsString('data:', $directives['script-src']);
    }

    public function testInlineScriptsOfTheImportMapCarryTheNonceOfThePolicy(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        $directives = $this->directives((string) $client->getResponse()->headers->get('Content-Security-Policy'));
        if (1 !== preg_match("/'nonce-([A-Za-z0-9_-]{20,})'/", $directives['script-src'], $matches)) {
            self::fail('script-src has no nonce.');
        }
        $nonce = $matches[1];

        $scripts = $crawler->filter('script');
        self::assertGreaterThan(0, $scripts->count());
        foreach ($scripts as $script) {
            self::assertInstanceOf(\DOMElement::class, $script);
            self::assertSame($nonce, $script->getAttribute('nonce'));
        }

        // A new nonce for every response.
        $client->request('GET', '/');
        self::assertStringNotContainsString($nonce, (string) $client->getResponse()->headers->get('Content-Security-Policy'));
    }

    public function testNoModuleIsADataUrl(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        // CSS imported from JavaScript becomes a "data:" module, which script-src refuses: such a
        // stylesheet is linked by base.html.twig instead.
        $importMap = json_decode($crawler->filter('script[type="importmap"]')->text(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($importMap);
        self::assertIsArray($importMap['imports']);
        self::assertArrayNotHasKey('/assets/styles/app.css', $importMap['imports']);
        self::assertArrayNotHasKey('/assets/@symfony/ux-live-component/live.min.css', $importMap['imports']);
        self::assertSelectorExists('link[rel="stylesheet"][href*="/assets/styles/app"]');
    }

    /**
     * @return array<string, string>
     */
    private function directives(string $policy): array
    {
        $directives = [];
        foreach (explode(';', $policy) as $directive) {
            $parts = explode(' ', trim($directive), 2);
            $directives[$parts[0]] = $parts[1] ?? '';
        }

        return $directives;
    }
}
