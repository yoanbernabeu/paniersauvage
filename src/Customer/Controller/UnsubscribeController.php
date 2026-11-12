<?php

declare(strict_types=1);

namespace App\Customer\Controller;

use App\Customer\Entity\Customer;
use App\Customer\Entity\ShopFollower;
use App\Customer\Repository\CustomerRepository;
use App\Customer\Service\FollowManager;
use App\Shop\Entity\Shop;
use App\Shop\Repository\ShopRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Attribute\Route;

/**
 * One-click unsubscribe from the sale announcements of a shop, from a signed link of an e-mail.
 * The signature stands for the customer: no sign-in, no CSRF token (mail clients POST the very same
 * URL, see RFC 8058 and the List-Unsubscribe-Post header).
 */
final class UnsubscribeController extends AbstractController
{
    public function __construct(
        private readonly UriSigner $uriSigner,
        private readonly ShopRepository $shops,
        private readonly CustomerRepository $customers,
        private readonly FollowManager $followManager,
    ) {
    }

    #[Route('/e-mails/ne-plus-recevoir/{shopId}/{customerId}', name: 'customer_unsubscribe', requirements: ['shopId' => '\d+', 'customerId' => '\d+'], methods: ['GET', 'POST'])]
    public function __invoke(Request $request, int $shopId, int $customerId): Response
    {
        if (!$this->uriSigner->checkRequest($request)) {
            throw $this->createNotFoundException('Invalid or tampered unsubscribe link.');
        }

        $shop = $this->shops->find($shopId) ?? throw $this->createNotFoundException();
        $customer = $this->customers->find($customerId) ?? throw $this->createNotFoundException();

        if ($request->isMethod('POST')) {
            $this->stopAnnouncements($customer, $shop);

            return $this->render('customer/unsubscribe/done.html.twig', ['shop' => $shop]);
        }

        return $this->render('customer/unsubscribe/confirm.html.twig', [
            'shop' => $shop,
            'action' => $request->getRequestUri(),
            'already_off' => !($this->followManager->find($customer, $shop)?->hasEmailConsent() ?? false),
        ]);
    }

    private function stopAnnouncements(Customer $customer, Shop $shop): void
    {
        $follower = $this->followManager->find($customer, $shop);

        if ($follower instanceof ShopFollower && $follower->hasEmailConsent()) {
            // Same preference as the "Me prévenir par e-mail" box of the account page.
            $this->followManager->follow($customer, $shop, false);
        }
    }
}
