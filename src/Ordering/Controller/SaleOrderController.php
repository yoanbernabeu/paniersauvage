<?php

declare(strict_types=1);

namespace App\Ordering\Controller;

use App\Customer\Entity\Customer;
use App\Customer\Service\LoginLinkSender;
use App\Legal\LegalDocuments;
use App\Ordering\Entity\Order;
use App\Ordering\Enum\OrderSource;
use App\Ordering\Exception\FirstOrderCapExceededException;
use App\Ordering\Exception\InsufficientStockException;
use App\Ordering\Exception\OrderNotAllowedException;
use App\Ordering\Form\OrderFormData;
use App\Ordering\Form\OrderFormType;
use App\Ordering\Repository\OrderRepository;
use App\Ordering\Service\GuestOrderSession;
use App\Ordering\Service\OrderCustomerResolver;
use App\Ordering\Service\OrderIcsBuilder;
use App\Ordering\Service\OrderManager;
use App\Sale\Entity\Sale;
use App\Sale\Repository\SaleRepository;
use App\Shared\Enum\Actor;
use App\Shop\Repository\ShopRepository;
use App\Shop\Search\CrossDiscovery;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Public order page of a sale, and the confirmation shown right after ordering.
 */
#[Route('/{slug}/vente/{code}', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*', 'code' => '[A-Za-z0-9]{1,12}'])]
final class SaleOrderController extends AbstractController
{
    public function __construct(
        private readonly ShopRepository $shops,
        private readonly SaleRepository $sales,
        private readonly OrderRepository $orders,
        private readonly OrderManager $orderManager,
        private readonly GuestOrderSession $guestOrders,
        private readonly ClockInterface $clock,
        private readonly RateLimiterFactoryInterface $guestOrderIpLimiter,
        private readonly RateLimiterFactoryInterface $guestOrderEmailLimiter,
    ) {
    }

    /**
     * Guests (not signed in) are protected against abuse: a honeypot field silently drops bots,
     * orders are rate-limited per IP and per e-mail address, and an address that already has an
     * active order in the sale gets a sign-in link to it instead of a second order.
     */
    #[Route('', name: 'sale_show', methods: ['GET', 'POST'])]
    public function show(string $slug, string $code, Request $request, OrderCustomerResolver $customerResolver, LoginLinkSender $loginLinkSender, #[CurrentUser] ?Customer $customer = null): Response
    {
        $sale = $this->findSale($slug, $code);
        $now = $this->clock->now();

        if (!$sale->acceptsOrders($now)) {
            return $this->render('ordering/sale/unavailable.html.twig', [
                'shop' => $sale->getShop(),
                'sale' => $sale,
                'deadline_passed' => $sale->isClosed() || ($sale->isOpen() && $now >= $sale->getDeadlineAt()),
            ]);
        }

        if ($customer instanceof Customer) {
            $existing = $this->orders->findActiveForCustomerInSale($customer, $sale);
            if ($existing instanceof Order) {
                return $this->redirectToRoute('customer_order_edit', ['reference' => $existing->getReference()]);
            }
        }

        // Where the customer came from: the link's `?via=`, then the hidden field of the form (no cookie).
        $source = OrderSource::fromVia($request->isMethod('POST') ? $request->request->get('via') : $request->query->get('via'));

        $this->sales->loadOffersAndDeliveries($sale);
        $data = OrderFormData::forSale($sale, $customer);
        $previous = $customer instanceof Customer ? $this->orders->findPreviousForCustomerInShop($customer, $sale->getShop(), $sale) : null;
        $resumed = $previous instanceof Order && $request->query->getBoolean('reprendre');
        if ($previous instanceof Order && $resumed) {
            $data->prefillFrom($previous, $sale);
        }

        $askTerms = !$customer instanceof Customer || !$customer->hasAcceptedTerms(LegalDocuments::TERMS_VERSION);
        $formOptions = ['sale' => $sale, 'identity' => true, 'email' => !$customer instanceof Customer, 'honeypot' => !$customer instanceof Customer, 'terms' => $askTerms, 'age_check' => true, 'offers' => $sale->getOrderableOffers()];
        $form = $this->createForm(OrderFormType::class, $data, $formOptions);
        $form->handleRequest($request);
        $stockMessages = [];

        $honeypot = $customer instanceof Customer ? null : $form->get(OrderFormType::HONEYPOT_FIELD)->getData();
        if ($form->isSubmitted() && \is_string($honeypot) && '' !== trim($honeypot)) {
            // Only bots fill the hidden field: drop the order without saying why.
            return $this->redirectToRoute('sale_show', ['slug' => $slug, 'code' => $code], Response::HTTP_SEE_OTHER);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$customer instanceof Customer) {
                $email = mb_strtolower(trim($data->email ?? ''));

                // Both limiters are consumed whatever the address: the answer never tells whether an account exists.
                $ipLimit = $this->guestOrderIpLimiter->create($request->getClientIp() ?? 'unknown')->consume();
                $emailLimit = $this->guestOrderEmailLimiter->create($email)->consume();

                if (!$ipLimit->isAccepted() || !$emailLimit->isAccepted()) {
                    $this->addFlash('error', 'Tu as passé beaucoup de commandes d\'un coup. Patiente un peu avant de réessayer, ou contacte directement le producteur.');

                    return $this->renderPage($sale, $form, $data, $customer, $resumed ? null : $previous, [], Response::HTTP_TOO_MANY_REQUESTS, $source);
                }

                $buyer = $customerResolver->resolve($email);

                $existing = null === $buyer->getId() ? null : $this->orders->findActiveForCustomerInSale($buyer, $sale);
                if ($existing instanceof Order) {
                    // One active order per customer and sale: the owner of the address gets a link to it.
                    $loginLinkSender->send($email, $this->generateUrl('customer_order_show', ['reference' => $existing->getReference()]));
                    $this->addFlash('info', 'Cette adresse a déjà une commande pour cette vente. On vient d\'envoyer à cette adresse un lien pour la retrouver et la modifier.');

                    return $this->redirectToRoute('sale_show', ['slug' => $slug, 'code' => $code], Response::HTTP_SEE_OTHER);
                }
            } else {
                // Only a signed-in customer updates their own profile.
                $buyer = $customer->setFirstName($data->firstName)->setLastName($data->lastName)->setPhone($data->phone);
            }

            // The box was ticked. A guest's tick is only recorded on an account it creates: whoever
            // typed an existing address cannot accept anything on behalf of its owner.
            if ($askTerms && ($customer instanceof Customer || null === $buyer->getId())) {
                $buyer->acceptTerms(LegalDocuments::TERMS_VERSION, $now);
            }

            try {
                $order = $this->orderManager->placeOrder($sale, $buyer, $data->toRequest(), Actor::Customer, $source);

                if (!$customer instanceof Customer) {
                    $this->guestOrders->remember($request->getSession(), $order);
                }

                return $this->redirectToRoute('sale_order_confirmed', [
                    'slug' => $sale->getShop()->getSlug(),
                    'code' => $sale->getCode(),
                    'reference' => $order->getReference(),
                ], Response::HTTP_SEE_OTHER);
            } catch (InsufficientStockException $exception) {
                $stockMessages = $data->applyShortages($exception);
                $form = $this->createForm(OrderFormType::class, $data, $formOptions);
            } catch (FirstOrderCapExceededException $exception) {
                // The basket stays as typed, so that the customer can lower it.
                return $this->renderPage($sale, $form, $data, $customer, $resumed ? null : $previous, [], Response::HTTP_UNPROCESSABLE_ENTITY, $source, $exception->getMessage());
            } catch (OrderNotAllowedException $exception) {
                $this->addFlash('error', $exception->getMessage());

                return $this->redirectToRoute('sale_show', ['slug' => $slug, 'code' => $code]);
            }
        }

        return $this->renderPage($sale, $form, $data, $customer, $resumed ? null : $previous, $stockMessages, [] === $stockMessages ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY, $source);
    }

    /**
     * @param FormInterface<OrderFormData> $form
     * @param list<string>                 $stockMessages
     */
    private function renderPage(Sale $sale, FormInterface $form, OrderFormData $data, ?Customer $customer, ?Order $previous, array $stockMessages, int $status, ?OrderSource $source, ?string $capMessage = null): Response
    {
        return $this->render('ordering/sale/show.html.twig', [
            'shop' => $sale->getShop(),
            'sale' => $sale,
            'offers' => $sale->getOrderableOffers(),
            'form' => $form,
            'data' => $data,
            'customer' => $customer,
            'previous_order' => $previous,
            'stock_messages' => $stockMessages,
            'cap_message' => $capMessage,
            'via' => $source?->via(),
        ], Response::HTTP_OK === $status ? null : new Response(status: $status));
    }

    #[Route('/merci/{reference}', name: 'sale_order_confirmed', methods: ['GET'])]
    public function confirmed(string $slug, string $code, string $reference, Request $request, CrossDiscovery $crossDiscovery, #[CurrentUser] ?Customer $customer = null): Response
    {
        $order = $this->findVisibleOrder($slug, $code, $reference, $request, $customer);

        return $this->render('ordering/sale/confirmed.html.twig', [
            'shop' => $order->getShop(),
            'sale' => $order->getSale(),
            'order' => $order,
            'is_guest' => !$customer instanceof Customer || $order->getCustomer()->getId() !== $customer->getId(),
            'editable' => $order->isCustomerEditable($this->clock->now()),
            'nearby_shops' => $crossDiscovery->around($order->getSaleDelivery()->getDeliveryPoint()),
        ]);
    }

    /**
     * Calendar file for the confirmation page (guests cannot reach `customer_order_ics`).
     */
    #[Route('/merci/{reference}/calendrier.ics', name: 'sale_order_ics', methods: ['GET'])]
    public function ics(string $slug, string $code, string $reference, Request $request, OrderIcsBuilder $icsBuilder, #[CurrentUser] ?Customer $customer = null): Response
    {
        $order = $this->findVisibleOrder($slug, $code, $reference, $request, $customer);

        return IcsResponse::create($icsBuilder->build($order), $order);
    }

    private function findSale(string $slug, string $code): Sale
    {
        $shop = $this->shops->findOneBySlug($slug) ?? throw $this->createNotFoundException('Unknown shop.');

        return $this->sales->findOneByShopAndCode($shop, $code) ?? throw $this->createNotFoundException('Unknown sale.');
    }

    /**
     * The order, when it belongs to the logged-in customer or was placed as a guest in this
     * session; 404 otherwise.
     */
    private function findVisibleOrder(string $slug, string $code, string $reference, Request $request, ?Customer $customer): Order
    {
        $sale = $this->findSale($slug, $code);
        $order = $this->orders->findOneByReference($reference);

        if (!$order instanceof Order || $order->getSale() !== $sale) {
            throw $this->createNotFoundException('Unknown order.');
        }

        $isOwner = $customer instanceof Customer && $order->getCustomer()->getId() === $customer->getId();
        $isGuestOfThisSession = $request->hasPreviousSession() && $this->guestOrders->knows($request->getSession(), $order);

        if (!$isOwner && !$isGuestOfThisSession) {
            throw $this->createNotFoundException('This order is not yours.');
        }

        return $order;
    }
}
