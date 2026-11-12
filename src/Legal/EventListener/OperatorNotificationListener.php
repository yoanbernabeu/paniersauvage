<?php

declare(strict_types=1);

namespace App\Legal\EventListener;

use App\Legal\Event\ShopFlaggedForReview;
use App\Legal\Event\ShopReported;
use App\Legal\Service\PublisherIdentity;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * E-mails the operator of the platform (LEGAL_CONTACT_EMAIL): shops to review, shop reports.
 * There is no administration interface: decisions are made with app:shops:review. A mail problem
 * is logged, never thrown at the visitor or the producer.
 */
final readonly class OperatorNotificationListener
{
    public function __construct(
        private MailerInterface $mailer,
        private PublisherIdentity $publisher,
        private UrlGeneratorInterface $urlGenerator,
        private LoggerInterface $logger,
        #[Autowire(env: 'MAILER_FROM')]
        private string $mailerFrom,
    ) {
    }

    #[AsEventListener]
    public function onShopFlaggedForReview(ShopFlaggedForReview $event): void
    {
        $verification = $event->verification;
        $shop = $verification->getShop();

        $this->send(\sprintf('Boutique à vérifier : %s', $shop->getName()), 'legal/email/shop_to_review', [
            'shop' => $shop,
            'verification' => $verification,
            'shop_url' => $this->shopUrl($shop->getSlug()),
        ]);
    }

    #[AsEventListener]
    public function onShopReported(ShopReported $event): void
    {
        $email = $this->compose(\sprintf('Signalement : %s', $event->shop->getName()), 'legal/email/shop_reported', [
            'report' => $event,
            'shop' => $event->shop,
            'shop_url' => $this->shopUrl($event->shop->getSlug()),
        ]);

        if (null !== $event->reporterEmail) {
            $email->replyTo($event->reporterEmail);
        }

        $this->deliver($email);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function send(string $subject, string $template, array $context): void
    {
        $this->deliver($this->compose($subject, $template, $context));
    }

    /**
     * @param array<string, mixed> $context
     */
    private function compose(string $subject, string $template, array $context): TemplatedEmail
    {
        return new TemplatedEmail()
            ->from(Address::create($this->mailerFrom))
            ->to($this->publisher->contactEmail)
            ->subject($subject)
            ->htmlTemplate($template.'.html.twig')
            ->textTemplate($template.'.txt.twig')
            ->context($context + ['subject' => $subject]);
    }

    private function deliver(TemplatedEmail $email): void
    {
        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $exception) {
            $this->logger->error('Operator e-mail not sent: {message}', ['message' => $exception->getMessage(), 'exception' => $exception]);
        }
    }

    private function shopUrl(string $slug): string
    {
        return $this->urlGenerator->generate('shop_show', ['slug' => $slug], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
