<?php

declare(strict_types=1);

namespace App\Recurring\Controller;

use App\Customer\Entity\Customer;
use App\Legal\LegalDocuments;
use App\Recurring\Entity\RecurringOccurrence;
use App\Recurring\Entity\RecurringOrder;
use App\Recurring\Entity\RecurringPlan;
use App\Recurring\Exception\RecurringOrderNotAllowedException;
use App\Recurring\Form\RecurringOrderFormData;
use App\Recurring\Form\RecurringOrderType;
use App\Recurring\Repository\RecurringOrderRepository;
use App\Recurring\Repository\RecurringPlanRepository;
use App\Recurring\Service\RecurringCalendar;
use App\Recurring\Service\RecurringOrderManager;
use App\Recurring\Service\RecurringPlanCatalog;
use App\Shop\Repository\ShopRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Customer side of the recurring orders: the public page of a plan (subscribe), and the
 * subscription in "Mon compte" (coming deliveries, pause, quantities, cancellation).
 */
final class CustomerRecurringController extends AbstractController
{
    private const string SLUG = '[a-z0-9]+(?:-[a-z0-9]+)*';

    public function __construct(
        private readonly RecurringPlanRepository $plans,
        private readonly RecurringOrderRepository $recurringOrders,
        private readonly RecurringOrderManager $manager,
        private readonly RecurringCalendar $calendar,
        private readonly RecurringPlanCatalog $catalog,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Public page of a plan; a signed-in customer subscribes on it. A visitor goes through the
     * magic link first (recurring_plan_login brings them back here).
     */
    #[Route('/{slug}/panier/{id}', name: 'recurring_plan_show', requirements: ['slug' => self::SLUG, 'id' => '\d+'], methods: ['GET', 'POST'])]
    public function show(string $slug, int $id, Request $request, ShopRepository $shops, EntityManagerInterface $entityManager, #[CurrentUser] ?Customer $customer = null): Response
    {
        $shop = $shops->findOneBySlug($slug) ?? throw $this->createNotFoundException('Unknown shop.');
        $plan = $this->plans->findOneInShop($id, $shop) ?? throw $this->createNotFoundException('Unknown plan.');

        $existing = $customer instanceof Customer ? $this->recurringOrders->findInProgress($plan, $customer) : null;
        if ($existing instanceof RecurringOrder) {
            return $this->redirectToRoute('customer_recurring_show', ['id' => $existing->getId()]);
        }

        if (!$customer instanceof Customer && $request->isMethod('POST')) {
            return $this->redirectToRoute('recurring_plan_login', ['slug' => $slug, 'id' => $id], Response::HTTP_SEE_OTHER);
        }

        $open = $this->catalog->isOpen($plan);
        $placesLeft = $this->catalog->placesLeft($plan);
        $askTerms = $customer instanceof Customer && !$customer->hasAcceptedTerms(LegalDocuments::TERMS_VERSION);
        $data = RecurringOrderFormData::forPlan($plan);
        $form = $this->createForm(RecurringOrderType::class, $data, ['plan' => $plan, 'subscribe' => true, 'terms' => $askTerms]);
        $form->handleRequest($request);

        if ($customer instanceof Customer && $form->isSubmitted() && $form->isValid()) {
            try {
                // Saved along with the subscription.
                if ($askTerms) {
                    $customer->acceptTerms(LegalDocuments::TERMS_VERSION, $this->clock->now());
                }
                $recurringOrder = $this->manager->subscribe($plan, $customer, $data->toRequest(), $data->adultCertified);
                $this->addFlash('success', 'Bienvenue parmi les abonnés 🧺 Tu vas recevoir un e-mail de confirmation, puis un autre à chaque commande créée.');

                return $this->redirectToRoute('customer_recurring_show', ['id' => $recurringOrder->getId()], Response::HTTP_SEE_OTHER);
            } catch (RecurringOrderNotAllowedException $exception) {
                $this->addFlash('error', $exception->getMessage());

                return $this->redirectToRoute('recurring_plan_show', ['slug' => $slug, 'id' => $id], Response::HTTP_SEE_OTHER);
            }
        }

        return $this->render('recurring/customer/plan.html.twig', [
            'shop' => $shop,
            'plan' => $plan,
            'form' => $form,
            'customer' => $customer,
            'open' => $open,
            'places_left' => $placesLeft,
            'season_dates' => $plan->remainingSeasonDates($this->clock->now()),
        ], $form->isSubmitted() ? new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY) : null);
    }

    /**
     * Entry point for visitors: the firewall sends them to the magic link, then back here.
     */
    #[Route('/{slug}/panier/{id}/connexion', name: 'recurring_plan_login', requirements: ['slug' => self::SLUG, 'id' => '\d+'], methods: ['GET'])]
    #[IsGranted(Customer::ROLE_CUSTOMER)]
    public function login(string $slug, int $id): RedirectResponse
    {
        return $this->redirectToRoute('recurring_plan_show', ['slug' => $slug, 'id' => $id]);
    }

    #[Route('/mon-compte/paniers/{id}', name: 'customer_recurring_show', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted(Customer::ROLE_CUSTOMER)]
    public function manage(int $id, Request $request, #[CurrentUser] Customer $customer, EntityManagerInterface $entityManager): Response
    {
        $recurringOrder = $this->find($id, $customer);
        $plan = $recurringOrder->getPlan();
        $data = RecurringOrderFormData::fromRecurringOrder($recurringOrder);
        $form = $this->createForm(RecurringOrderType::class, $data, ['plan' => $plan]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->manager->update($recurringOrder, $data->toRequest());
                $this->addFlash('success', 'C\'est noté : tes prochaines commandes suivront ces changements.');

                return $this->redirectToRoute('customer_recurring_show', ['id' => $id], Response::HTTP_SEE_OTHER);
            } catch (RecurringOrderNotAllowedException $exception) {
                $entityManager->refresh($recurringOrder);
                $this->addFlash('error', $exception->getMessage());
            }
        }

        return $this->renderManage($recurringOrder, $plan, $form, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
    }

    #[Route('/mon-compte/paniers/{id}/pause', name: 'customer_recurring_pause', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(Customer::ROLE_CUSTOMER)]
    public function pause(int $id, Request $request, #[CurrentUser] Customer $customer): RedirectResponse
    {
        return $this->onDelivery($id, $request, $customer, function (RecurringOrder $recurringOrder, \DateTimeImmutable $day): string {
            $this->manager->pause($recurringOrder, $day);

            return 'Cette livraison est en pause : pas de commande ce jour-là.';
        });
    }

    #[Route('/mon-compte/paniers/{id}/reprise', name: 'customer_recurring_unpause', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(Customer::ROLE_CUSTOMER)]
    public function unpause(int $id, Request $request, #[CurrentUser] Customer $customer): RedirectResponse
    {
        return $this->onDelivery($id, $request, $customer, function (RecurringOrder $recurringOrder, \DateTimeImmutable $day): string {
            $this->manager->resume($recurringOrder, $day);

            return 'Cette livraison est reprise.';
        });
    }

    #[Route('/mon-compte/paniers/{id}/resilier', name: 'customer_recurring_cancel', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(Customer::ROLE_CUSTOMER)]
    public function cancel(int $id, Request $request, #[CurrentUser] Customer $customer): RedirectResponse
    {
        $recurringOrder = $this->find($id, $customer);
        $this->assertCsrf($request);

        try {
            $lastDay = $this->manager->cancel($recurringOrder);
            $this->addFlash('success', $lastDay instanceof \DateTimeImmutable
                ? 'Ta résiliation est enregistrée. Préavis oblige, les livraisons jusqu\'au '.$lastDay->format('d/m/Y').' restent prévues.'
                : 'Ton abonnement est résilié : plus aucune commande ne sera créée. Les commandes déjà créées restent prévues, tu peux les annuler depuis leur page.');
        } catch (RecurringOrderNotAllowedException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('customer_recurring_show', ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    /**
     * @param FormInterface<RecurringOrderFormData> $form
     */
    private function renderManage(RecurringOrder $recurringOrder, RecurringPlan $plan, FormInterface $form, int $status): Response
    {
        $now = $this->clock->now();

        return $this->render('recurring/customer/manage.html.twig', [
            'recurring_order' => $recurringOrder,
            'plan' => $plan,
            'shop' => $plan->getShop(),
            'form' => $form,
            'upcoming' => $this->calendar->upcoming($recurringOrder, $now),
            'history' => array_filter($recurringOrder->getOccurrences()->toArray(), static fn (RecurringOccurrence $occurrence): bool => $occurrence->getDeliveryDate() < $now->setTime(0, 0)),
            'today' => $now,
        ], Response::HTTP_OK === $status ? null : new Response(status: $status));
    }

    /**
     * @param \Closure(RecurringOrder, \DateTimeImmutable): string $action returns the success message
     */
    private function onDelivery(int $id, Request $request, Customer $customer, \Closure $action): RedirectResponse
    {
        $recurringOrder = $this->find($id, $customer);
        $this->assertCsrf($request);

        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $request->getPayload()->getString('date'));
        if (false === $day) {
            $this->addFlash('error', 'Cette livraison ne fait pas partie de tes prochaines livraisons.');
        } else {
            try {
                $this->addFlash('success', $action($recurringOrder, $day));
            } catch (RecurringOrderNotAllowedException $exception) {
                $this->addFlash('error', $exception->getMessage());
            }
        }

        return $this->redirectToRoute('customer_recurring_show', ['id' => $id, '_fragment' => 'livraisons'], Response::HTTP_SEE_OTHER);
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('submit', $request->getPayload()->getString('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    private function find(int $id, Customer $customer): RecurringOrder
    {
        return $this->recurringOrders->findOneOfCustomer($id, $customer) ?? throw $this->createNotFoundException();
    }
}
