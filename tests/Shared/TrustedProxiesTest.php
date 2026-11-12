<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * X-Forwarded-* headers are trusted only from the proxies listed in TRUSTED_PROXIES.
 */
final class TrustedProxiesTest extends KernelTestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['TRUSTED_PROXIES'], $_ENV['TRUSTED_PROXIES']);
        Request::setTrustedProxies([], 0);
        parent::tearDown();
    }

    public function testForwardedHeadersAreIgnoredByDefault(): void
    {
        Request::setTrustedProxies([], 0);
        self::bootKernel();

        $request = $this->proxiedRequest();

        self::assertSame('10.0.0.5', $request->getClientIp());
        self::assertFalse($request->isSecure());
    }

    public function testForwardedHeadersOfAListedProxyAreTrusted(): void
    {
        $_SERVER['TRUSTED_PROXIES'] = $_ENV['TRUSTED_PROXIES'] = '10.0.0.0/8, 192.168.1.1';
        self::bootKernel();

        $request = $this->proxiedRequest();

        self::assertSame('203.0.113.7', $request->getClientIp());
        self::assertTrue($request->isSecure());
        self::assertSame('boutique.example.fr', $request->getHost());
    }

    public function testAnUnlistedClientCannotForgeItsAddress(): void
    {
        $_SERVER['TRUSTED_PROXIES'] = $_ENV['TRUSTED_PROXIES'] = '192.168.1.1';
        self::bootKernel();

        self::assertSame('10.0.0.5', $this->proxiedRequest()->getClientIp());
    }

    private function proxiedRequest(): Request
    {
        return Request::create('http://localhost/', server: [
            'REMOTE_ADDR' => '10.0.0.5',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.7',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'boutique.example.fr',
        ]);
    }
}
