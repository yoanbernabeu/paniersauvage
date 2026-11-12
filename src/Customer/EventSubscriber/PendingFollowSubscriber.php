<?php

declare(strict_types=1);

namespace App\Customer\EventSubscriber;

use App\Customer\Entity\Customer;
use App\Customer\Service\FollowManager;
use App\Customer\Service\PendingFollow;
use App\Shop\Entity\Shop;
use App\Shop\Repository\ShopRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Applies the follow an anonymous visitor asked for, right after they signed in.
 */
final readonly class PendingFollowSubscriber
{
    public function __construct(
        private PendingFollow $pendingFollow,
        private FollowManager $followManager,
        private ShopRepository $shops,
    ) {
    }

    #[AsEventListener]
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $customer = $event->getUser();
        $request = $event->getRequest();

        if ('main' !== $event->getFirewallName() || !$customer instanceof Customer || !$request->hasSession()) {
            return;
        }

        $session = $request->getSession();
        $pending = $this->pendingFollow->take($session);
        $shop = null === $pending ? null : $this->shops->findOneBySlug($pending['slug']);

        if (null === $pending || !$shop instanceof Shop) {
            return;
        }

        $this->followManager->follow($customer, $shop, $pending['consent']);

        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('success', \sprintf('C\'est noté : tu suis maintenant %s.', $shop->getName()));
        }
    }
}
