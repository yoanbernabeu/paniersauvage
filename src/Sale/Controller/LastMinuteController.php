<?php

declare(strict_types=1);

namespace App\Sale\Controller;

use App\Customer\Repository\ShopFollowerRepository;
use App\Sale\Entity\Sale;
use App\Sale\Exception\LastMinuteNotAllowed;
use App\Sale\Form\LastMinuteSaleType;
use App\Sale\Form\Model\LastMinuteSaleData;
use App\Sale\Repository\SaleRepository;
use App\Sale\Service\LastMinuteSale;
use App\Shop\Service\CurrentShop;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Leftovers of a closed sale put back on sale for a short while (SPEC §21, lot F).
 */
#[Route('/pro/ventes/{id}/derniere-minute', requirements: ['id' => '\d+'])]
final class LastMinuteController extends AbstractController
{
    /** CSRF token id of the "stop now" button. */
    public const string END_CSRF_ID = 'sale-last-minute-end';

    public function __construct(
        private readonly CurrentShop $currentShop,
        private readonly SaleRepository $sales,
        private readonly LastMinuteSale $lastMinute,
        private readonly ShopFollowerRepository $followers,
    ) {
    }

    #[Route('', name: 'pro_sale_last_minute', methods: ['GET', 'POST'])]
    public function open(int $id, Request $request): Response
    {
        $sale = $this->findSale($id);

        $refusal = $this->lastMinute->refusal($sale);
        if (null !== $refusal) {
            $this->addFlash('error', $refusal);

            return $this->redirectToRoute('pro_sale_show', ['id' => $sale->getId()], Response::HTTP_SEE_OTHER);
        }

        $offers = $sale->getOffers()->getValues();
        $data = LastMinuteSaleData::prefilled($offers, $this->lastMinute->defaultEnd($sale));
        $form = $this->createForm(LastMinuteSaleType::class, $data, ['offers' => $offers]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $data->until instanceof \DateTimeImmutable) {
            try {
                $this->lastMinute->open($sale, $data->until, $data->quantities);
                $this->addFlash('success', 'Ta vente de dernière minute est ouverte. Tes abonnés qui acceptent tes e-mails sont prévenus 🧺');

                return $this->redirectToRoute('pro_sale_show', ['id' => $sale->getId()], Response::HTTP_SEE_OTHER);
            } catch (LastMinuteNotAllowed $exception) {
                $form->addError(new FormError($exception->getMessage()));
            }
        }

        return $this->render('sale/last_minute.html.twig', [
            'sale' => $sale,
            'form' => $form,
            'offers' => $offers,
            'latest_end' => $this->lastMinute->latestEnd($sale),
            'followers' => $this->followers->count(['shop' => $sale->getShop(), 'emailConsent' => true]),
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/arreter', name: 'pro_sale_last_minute_end', methods: ['POST'])]
    public function end(int $id, Request $request): RedirectResponse
    {
        $sale = $this->findSale($id);

        if (!$this->isCsrfTokenValid(self::END_CSRF_ID, $request->getPayload()->getString('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $this->lastMinute->end($sale);
        $this->addFlash('success', 'La vente de dernière minute est terminée : plus aucune commande en ligne.');

        return $this->redirectToRoute('pro_sale_show', ['id' => $sale->getId()], Response::HTTP_SEE_OTHER);
    }

    private function findSale(int $id): Sale
    {
        return $this->sales->findOneInShop($id, $this->currentShop->get()) ?? throw $this->createNotFoundException();
    }
}
