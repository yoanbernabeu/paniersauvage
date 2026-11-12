<?php

declare(strict_types=1);

namespace App\Tests\Shop;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class ImageUrlTest extends KernelTestCase
{
    public function testUploadedPathsAbsolutePathsAndUrlsAreHandledAlike(): void
    {
        $twig = self::getContainer()->get(Environment::class);
        $render = static fn (?string $path): string => $twig->createTemplate('{{ path|image_url }}')->render(['path' => $path]);

        self::assertSame('/uploads/shops/3/logo.jpg', $render('uploads/shops/3/logo.jpg'));
        self::assertSame('/images/demo.jpg', $render('/images/demo.jpg'));
        self::assertSame('https://example.test/logo.png', $render('https://example.test/logo.png'));
        self::assertSame('', $render(null));
    }
}
