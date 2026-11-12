<?php

declare(strict_types=1);

namespace App\Sale\Controller;

use App\Ordering\Service\OrderSummarizer;
use App\Ordering\Service\SaleOrders;
use App\Sale\Entity\Sale;
use App\Sale\Enum\SaleStatus;
use App\Sale\Exception\SaleEditConflict;
use App\Sale\Form\SaleType;
use App\Sale\Repository\SaleRepository;
use App\Sale\Service\LastMinuteSale;
use App\Sale\Service\SaleDataFactory;
use App\Sale\Service\SaleEditor;
use App\Sale\Service\SaleLifecycle;
use App\Sale\Service\SaleShareLink;
use App\Sale\Service\SubscriptionGate;
use App\Shop\Service\CurrentShop;
use App\Shop\Service\OnboardingNudge;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/pro/ventes')]
final class SaleController extends AbstractController
{
    /** CSRF token id of the cancel button (stateless, see config/packages/csrf.yaml). */
    public const string CANCEL_CSRF_ID = 'sale-cancel';

    private const int PAST_SALES_SHOWN = 30;

    public function __construct(
        private readonly CurrentShop $currentShop,
        private readonly SaleRepository $sales,
        private readonly SaleOrders $saleOrders,
        private readonly SaleDataFactory $dataFactory,
        private readonly SaleEditor $editor,
        private readonly SaleLifecycle $lifecycle,
        private readonly SubscriptionGate $subscriptionGate,
        private readonly SaleShareLink $shareLink,
        private readonly OrderSummarizer $summarizer,
        private readonly LastMinuteSale $lastMinute,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('', name: 'pro_sale_index', methods: ['GET'])]
    public function index(): Response
    {
        $shop = $this->currentShop->get();

        $open = $this->sales->findByShopAndStatuses($shop, [SaleStatus::Open]);
        $scheduled = $this->sales->findByShopAndStatuses($shop, [SaleStatus::Scheduled]);
        $closed = $this->sales->findByShopAndStatuses($shop, [SaleStatus::Closed]);
        $past = $this->sales->findByShopAndStatuses($shop, [SaleStatus::Done, SaleStatus::Cancelled], true, self::PAST_SALES_SHOWN);

        return $this->render('sale/index.html.twig', [
            'open_sales' => $open,
            'scheduled_sales' => $scheduled,
            'closed_sales' => $closed,
            'past_sales' => $past,
            'order_counts' => $this->saleOrders->countBySale([...$open, ...$scheduled, ...$closed, ...$past]),
            'allows_new_sales' => $this->subscriptionGate->allowsNewSales($shop),
        ]);
    }

    #[Route('/nouvelle', name: 'pro_sale_new', methods: ['GET', 'POST'])]
    public function new(Request $request, OnboardingNudge $onboardingNudge): Response
    {
        $shop = $this->currentShop->get();

        if (!$this->subscriptionGate->allowsNewSales($shop)) {
            $this->addFlash('error', 'Ton abonnement ne permet plus de créer de nouvelles ventes. Tes ventes en cours vont à leur terme et tes données restent consultables.');

            return $this->redirectToRoute('pro_sale_index', status: Response::HTTP_SEE_OTHER);
        }

        $data = $this->dataFactory->forNewSale($shop);
        $form = $this->createForm(SaleType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $sale = $this->editor->create($shop, $data);
            $opened = $this->lifecycle->openIfDue($sale);

            $this->addFlash('success', $opened
                ? 'Ta vente est ouverte : partage son lien à tes clients.'
                : 'Ta vente est programmée : elle ouvrira toute seule à la date prévue.');
            $onboardingNudge->suggestNextStep($shop);

            return $this->redirectToRoute('pro_sale_show', ['id' => $sale->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('sale/form.html.twig', ['form' => $form, 'sale' => null]);
    }

    #[Route('/{id}', name: 'pro_sale_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): Response
    {
        $sale = $this->findSale($id);
        $orders = $this->saleOrders->forSale($sale);

        $groups = [];
        foreach ($sale->getDeliveries() as $delivery) {
            $groups[] = ['delivery' => $delivery, 'orders' => $this->saleOrders->forDelivery($delivery, $orders)];
        }

        return $this->render('sale/show.html.twig', [
            'sale' => $sale,
            'orders' => $orders,
            'groups' => $groups,
            'total_cents' => $this->summarizer->totalCents($orders),
            'production' => $this->saleOrders->productionTotals($sale, $orders),
            'public_url' => $this->shareLink->publicUrl($sale),
            'whatsapp_url' => $this->shareLink->whatsAppUrl($sale),
            // Leftovers and last-minute sale (SPEC §21, lot F).
            'leftovers' => $sale->isClosed() ? $this->lastMinute->leftovers($sale) : [],
            'last_minute_open' => $sale->isLastMinuteOpen($this->clock->now()),
            'last_minute_refusal' => $this->lastMinute->refusal($sale),
        ]);
    }

    #[Route('/{id}/modifier', name: 'pro_sale_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request): Response
    {
        $sale = $this->findSale($id);

        if (!$sale->getStatus()->isOngoing()) {
            $this->addFlash('error', 'Cette vente est terminée ou annulée : elle ne peut plus être modifiée.');

            return $this->redirectToRoute('pro_sale_show', ['id' => $sale->getId()], Response::HTTP_SEE_OTHER);
        }

        $data = $this->dataFactory->fromSale($sale);
        $form = $this->createForm(SaleType::class, $data, [
            'dates_locked' => $sale->isClosed(),
            'opening_locked' => !$sale->isScheduled(),
            'delivery_date_locked' => $data->deliveryDateLocked,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->editor->update($sale, $data);
                $this->lifecycle->openIfDue($sale);
                $this->addFlash('success', 'Ta vente est enregistrée.');

                return $this->redirectToRoute('pro_sale_show', ['id' => $sale->getId()], Response::HTTP_SEE_OTHER);
            } catch (SaleEditConflict $conflict) {
                $form->addError(new FormError($conflict->getMessage()));
            }
        }

        return $this->render('sale/form.html.twig', ['form' => $form, 'sale' => $sale]);
    }

    #[Route('/{id}/annuler', name: 'pro_sale_cancel', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function cancel(int $id, Request $request): RedirectResponse
    {
        $sale = $this->findSale($id);

        if (!$this->isCsrfTokenValid(self::CANCEL_CSRF_ID, $request->getPayload()->getString('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if ($sale->getStatus()->isOngoing()) {
            $this->lifecycle->cancel($sale);
            $this->addFlash('success', 'Ta vente est annulée. Les clients qui avaient commandé sont prévenus par e-mail.');
        } else {
            $this->addFlash('error', 'Cette vente est déjà terminée ou annulée.');
        }

        return $this->redirectToRoute('pro_sale_show', ['id' => $sale->getId()], Response::HTTP_SEE_OTHER);
    }

    private function findSale(int $id): Sale
    {
        return $this->sales->findOneInShop($id, $this->currentShop->get()) ?? throw $this->createNotFoundException();
    }
}
