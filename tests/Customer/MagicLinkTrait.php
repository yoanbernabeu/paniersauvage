<?php

declare(strict_types=1);

namespace App\Tests\Customer;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\Mime\Email;

/**
 * Reads the sign-in (or account-creation) link out of the last e-mail sent during a WebTestCase,
 * and follows it the way a person does: open the link, then press the confirmation button.
 */
trait MagicLinkTrait
{
    private function lastEmail(): Email
    {
        $messages = self::getMailerMessages();
        $email = end($messages);
        self::assertInstanceOf(Email::class, $email);

        return $email;
    }

    /**
     * @return string the path and query string of the sign-in or account-creation link
     */
    private function linkOf(Email $email): string
    {
        if (1 !== preg_match('#https?://[^/\s]+(/connexion/(?:verification|nouveau-compte)\S+)#', (string) $email->getTextBody(), $matches)) {
            self::fail('The e-mail contains no sign-in link.');
        }

        return $matches[1];
    }

    /**
     * Opens the link (nothing happens yet), then presses the button of the confirmation page.
     */
    private function confirmLink(KernelBrowser $client, string $link): void
    {
        $crawler = $client->request('GET', $link);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-confirm-email]');

        $form = $crawler->filter('main form')->form();
        $terms = $form->has('accept_terms') ? $form->get('accept_terms') : null;
        if ($terms instanceof ChoiceFormField) {
            // Account creation: the terms of use must be accepted.
            $terms->tick();
        }

        $client->submit($form);
    }
}
