<?php

declare(strict_types=1);

namespace App\Recurring\Service;

use App\Notification\Service\NotificationLinks;
use App\Recurring\Entity\RecurringOrder;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\UrlHelper;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\LoginLink\LoginLinkHandlerInterface;

/**
 * Absolute URLs of the subscription e-mails.
 */
final readonly class RecurringLinks
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private UrlHelper $urlHelper,
        // The handler of the customer firewall (the current request may be a worker or a producer page).
        #[Autowire(service: 'security.authenticator.login_link_handler.main')]
        private LoginLinkHandlerInterface $loginLinkHandler,
    ) {
    }

    /**
     * Magic link: logs the subscriber in, then lands on their subscription (pause, quantities).
     */
    public function subscription(RecurringOrder $recurringOrder): string
    {
        $target = $this->urlGenerator->generate('customer_recurring_show', ['id' => $recurringOrder->getId()]);
        $loginUrl = $this->loginLinkHandler->createLoginLink($recurringOrder->getCustomer(), null, NotificationLinks::LOGIN_LINK_LIFETIME)->getUrl();

        return $loginUrl.(str_contains($loginUrl, '?') ? '&' : '?').http_build_query(['_target_path' => $target]);
    }

    /**
     * Producer screen of the subscribers.
     */
    public function proIndex(): string
    {
        return $this->urlHelper->getAbsoluteUrl($this->urlGenerator->generate('pro_recurring_index'));
    }
}
