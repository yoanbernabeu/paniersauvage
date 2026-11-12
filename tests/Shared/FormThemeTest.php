<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class FormThemeTest extends WebTestCase
{
    public function testFieldsPointAtTheirHelpAndTheirErrors(): void
    {
        $client = self::createClient();
        // The sign-up limiter lives in the cache, which outlasts a test.
        self::getContainer()->get('limiter.registration')->create('127.0.0.1')->reset();

        $crawler = $client->request('GET', '/pro/inscription');
        self::assertSame('registration_plainPassword_help', $crawler->filter('#registration_plainPassword')->attr('aria-describedby'));
        self::assertNull($crawler->filter('#registration_email')->attr('aria-invalid'));

        $crawler = $client->submitForm('Créer ma boutique', ['registration[email]' => 'pas-un-email', 'registration[plainPassword]' => 'court']);

        self::assertResponseStatusCodeSame(422);
        $email = $crawler->filter('#registration_email');
        self::assertSame('true', $email->attr('aria-invalid'));
        self::assertSame('registration_email_errors', $email->attr('aria-describedby'));
        self::assertSelectorTextContains('#registration_email_errors', 'ne semble pas valide');

        $password = $crawler->filter('#registration_plainPassword');
        self::assertSame('true', $password->attr('aria-invalid'));
        self::assertSame('registration_plainPassword_help registration_plainPassword_errors', $password->attr('aria-describedby'));
    }
}
