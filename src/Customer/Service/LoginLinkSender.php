<?php

declare(strict_types=1);

namespace App\Customer\Service;

use App\Customer\Entity\Customer;
use App\Customer\Repository\CustomerRepository;
use App\Instance\InstanceSettings;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\LoginLink\LoginLinkHandlerInterface;

/**
 * E-mails a magic sign-in link. Nothing is created for an unknown address: its owner receives a
 * signed link to create the account themselves (SignupController), so nobody can open an account
 * in someone else's name. The screen says the same thing in both cases.
 */
final readonly class LoginLinkSender
{
    /** Lifetime of the account-creation link sent to an unknown address, in seconds. */
    public const int SIGNUP_LINK_LIFETIME = 3600;

    public function __construct(
        private CustomerRepository $customers,
        private LoginLinkHandlerInterface $loginLinkHandler,
        private MailerInterface $mailer,
        private ClockInterface $clock,
        private UriSigner $uriSigner,
        private UrlGeneratorInterface $urlGenerator,
        private InstanceSettings $instance,
    ) {
    }

    /**
     * @param string|null $targetPath local path (starting with "/") to land on once signed in
     */
    public function send(string $email, ?string $targetPath = null): void
    {
        $email = mb_strtolower(trim($email));
        $customer = $this->customers->findOneByEmail($email);

        if (!$customer instanceof Customer) {
            $this->sendSignupLink($email, $targetPath);

            return;
        }

        $link = $this->loginLinkHandler->createLoginLink($customer);
        $url = $link->getUrl();

        if (null !== $targetPath) {
            // Not part of the signature: it only tells the success handler where to go.
            $url .= (str_contains($url, '?') ? '&' : '?').'_target_path='.rawurlencode($targetPath);
        }

        $this->mailer->send(
            new TemplatedEmail()
                ->to($email)
                ->subject('Ton lien de connexion à '.$this->instance->name)
                ->htmlTemplate('customer/email/login_link.html.twig')
                ->textTemplate('customer/email/login_link.txt.twig')
                ->context([
                    'url' => $url,
                    'first_name' => $customer->getFirstName(),
                    'minutes' => $this->minutesUntil($link->getExpiresAt()),
                ]),
        );
    }

    private function sendSignupLink(string $email, ?string $targetPath): void
    {
        $parameters = ['email' => $email];
        if (null !== $targetPath) {
            $parameters['_target_path'] = $targetPath;
        }

        $expiresAt = $this->clock->now()->modify(\sprintf('+%d seconds', self::SIGNUP_LINK_LIFETIME));
        $url = $this->uriSigner->sign($this->urlGenerator->generate('customer_signup', $parameters, UrlGeneratorInterface::ABSOLUTE_URL), $expiresAt);

        $this->mailer->send(
            new TemplatedEmail()
                ->to($email)
                ->subject('Ton lien de connexion à '.$this->instance->name)
                ->htmlTemplate('customer/email/signup_link.html.twig')
                ->textTemplate('customer/email/signup_link.txt.twig')
                ->context([
                    'url' => $url,
                    'minutes' => $this->minutesUntil($expiresAt),
                ]),
        );
    }

    private function minutesUntil(\DateTimeImmutable $expiresAt): int
    {
        return max(1, (int) ceil(($expiresAt->getTimestamp() - $this->clock->now()->getTimestamp()) / 60));
    }
}
