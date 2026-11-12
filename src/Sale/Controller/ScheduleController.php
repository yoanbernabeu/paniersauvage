<?php

declare(strict_types=1);

namespace App\Sale\Controller;

use App\Catalog\Entity\Product;
use App\Catalog\Repository\ProductRepository;
use App\Delivery\Entity\DeliveryPoint;
use App\Delivery\Repository\DeliveryPointRepository;
use App\Sale\Entity\SaleSchedule;
use App\Sale\Form\SaleScheduleType;
use App\Sale\Repository\SaleScheduleRepository;
use App\Sale\Service\SaleGenerator;
use App\Sale\Service\SaleLifecycle;
use App\Sale\Service\SubscriptionGate;
use App\Shop\Service\CurrentShop;
use App\Shop\Service\OnboardingNudge;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/pro/recurrences')]
final class ScheduleController extends AbstractController
{
    public function __construct(
        private readonly CurrentShop $currentShop,
        private readonly SaleScheduleRepository $schedules,
        private readonly ProductRepository $products,
        private readonly DeliveryPointRepository $deliveryPoints,
        private readonly SaleGenerator $generator,
        private readonly SaleLifecycle $lifecycle,
        private readonly SubscriptionGate $subscriptionGate,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'pro_schedule_index', methods: ['GET'])]
    public function index(): Response
    {
        $shop = $this->currentShop->get();

        return $this->render('sale/schedule/index.html.twig', [
            'schedules' => $this->schedules->findByShop($shop),
            'allows_new_sales' => $this->subscriptionGate->allowsNewSales($shop),
        ]);
    }

    #[Route('/nouvelle', name: 'pro_schedule_new', methods: ['GET', 'POST'])]
    public function new(Request $request, OnboardingNudge $onboardingNudge): Response
    {
        $shop = $this->currentShop->get();
        $points = $this->deliveryPoints->findByShop($shop);
        $products = $this->products->findByShop($shop);

        $schedule = new SaleSchedule($shop);
        if (1 === \count($points)) {
            $schedule->addDeliveryPoint($points[0]);
        }
        foreach ($products as $product) {
            if ($product->isPermanent()) {
                $schedule->addProduct($product);
            }
        }

        $form = $this->createScheduleForm($schedule, $points, $products);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($schedule);
            $this->entityManager->flush();
            $this->generateAndReport($schedule, 'Ta récurrence est créée.');
            $onboardingNudge->suggestNextStep($shop);

            return $this->redirectToRoute('pro_schedule_index', status: Response::HTTP_SEE_OTHER);
        }

        return $this->render('sale/schedule/form.html.twig', [
            'form' => $form,
            'schedule' => null,
            'has_points' => [] !== $points,
            'has_products' => [] !== $products,
            'allows_new_sales' => $this->subscriptionGate->allowsNewSales($shop),
        ]);
    }

    #[Route('/{id}/modifier', name: 'pro_schedule_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request): Response
    {
        $schedule = $this->findSchedule($id);
        $shop = $schedule->getShop();

        // Archived points and products still attached to the recurrence stay listed, so they can be unticked.
        $points = $this->deliveryPoints->findByShop($shop);
        foreach ($schedule->getDeliveryPoints() as $point) {
            if (!\in_array($point, $points, true)) {
                $points[] = $point;
            }
        }
        $products = $this->products->findByShop($shop);
        foreach ($schedule->getProducts() as $product) {
            if (!\in_array($product, $products, true)) {
                $products[] = $product;
            }
        }

        $form = $this->createScheduleForm($schedule, $points, $products);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();
            $this->generateAndReport($schedule, 'Ta récurrence est enregistrée. Les ventes déjà créées ne changent pas.');

            return $this->redirectToRoute('pro_schedule_index', status: Response::HTTP_SEE_OTHER);
        }

        if ($form->isSubmitted()) {
            // Invalid values were written to the managed entity by the form: forget them.
            $this->entityManager->refresh($schedule);
        }

        return $this->render('sale/schedule/form.html.twig', [
            'form' => $form,
            'schedule' => $schedule,
            'has_points' => [] !== $points,
            'has_products' => [] !== $products,
            'allows_new_sales' => $this->subscriptionGate->allowsNewSales($shop),
        ]);
    }

    #[Route('/{id}/activation', name: 'pro_schedule_toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggle(int $id, Request $request): RedirectResponse
    {
        $schedule = $this->findSchedule($id);

        if (!$this->isCsrfTokenValid('submit', $request->getPayload()->getString('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $schedule->setActive(!$schedule->isActive());
        $this->entityManager->flush();

        if ($schedule->isActive()) {
            $this->generateAndReport($schedule, 'Ta récurrence est réactivée.');
        } else {
            $this->addFlash('success', 'Ta récurrence est en pause : plus aucune vente ne sera créée. Les ventes déjà créées restent en place.');
        }

        return $this->redirectToRoute('pro_schedule_index', status: Response::HTTP_SEE_OTHER);
    }

    private function findSchedule(int $id): SaleSchedule
    {
        return $this->schedules->findOneInShop($id, $this->currentShop->get()) ?? throw $this->createNotFoundException();
    }

    /**
     * @param list<DeliveryPoint> $points
     * @param list<Product>       $products
     *
     * @return FormInterface<SaleSchedule>
     */
    private function createScheduleForm(SaleSchedule $schedule, array $points, array $products): FormInterface
    {
        return $this->createForm(SaleScheduleType::class, $schedule, ['delivery_points' => $points, 'products' => $products]);
    }

    /**
     * Generates the coming sales of the recurrence, opens those already due, and tells the producer.
     */
    private function generateAndReport(SaleSchedule $schedule, string $message): void
    {
        if (!$schedule->isActive()) {
            $this->addFlash('success', $message);

            return;
        }

        if (!$this->subscriptionGate->allowsNewSales($schedule->getShop())) {
            $this->addFlash('success', $message);
            $this->addFlash('warning', 'Ton abonnement ne permet plus de créer de nouvelles ventes : aucune vente n\'a été générée.');

            return;
        }

        $sales = $this->generator->generateForSchedule($schedule);
        foreach ($sales as $sale) {
            $this->lifecycle->openIfDue($sale);
        }

        $count = \count($sales);
        $this->addFlash('success', match (true) {
            0 === $count => $message,
            1 === $count => $message.' 1 vente à venir a été créée.',
            default => \sprintf('%s %d ventes à venir ont été créées.', $message, $count),
        });
    }
}
