<?php

declare(strict_types=1);

namespace App\Delivery\Controller;

use App\Delivery\Entity\DeliveryPoint;
use App\Delivery\Form\DeliveryPointType;
use App\Delivery\Repository\DeliveryPointRepository;
use App\Shop\Service\CurrentShop;
use App\Shop\Service\OnboardingNudge;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/pro/points-de-livraison')]
final class DeliveryPointController extends AbstractController
{
    public function __construct(
        private readonly CurrentShop $currentShop,
        private readonly DeliveryPointRepository $deliveryPoints,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'pro_delivery_point_index', methods: ['GET'])]
    public function index(): Response
    {
        $active = $archived = [];

        foreach ($this->deliveryPoints->findByShop($this->currentShop->get(), includeArchived: true) as $point) {
            if ($point->isArchived()) {
                $archived[] = $point;
            } else {
                $active[] = $point;
            }
        }

        return $this->render('delivery/point/index.html.twig', ['active' => $active, 'archived' => $archived]);
    }

    #[Route('/nouveau', name: 'pro_delivery_point_new', methods: ['GET', 'POST'])]
    public function new(Request $request, OnboardingNudge $onboardingNudge): Response
    {
        $point = new DeliveryPoint($this->currentShop->get());
        $form = $this->createForm(DeliveryPointType::class, $point);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($point);
            $this->entityManager->flush();
            $this->addFlash('success', \sprintf('« %s » est ajouté à tes points de livraison.', $point->getName()));
            $onboardingNudge->suggestNextStep($point->getShop());

            return $this->redirectToRoute('pro_delivery_point_index');
        }

        return $this->render('delivery/point/form.html.twig', ['point' => $point, 'form' => $form]);
    }

    #[Route('/{id}/modifier', name: 'pro_delivery_point_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request): Response
    {
        $point = $this->findPoint($id);
        $form = $this->createForm(DeliveryPointType::class, $point);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();
            $this->addFlash('success', \sprintf('« %s » est à jour.', $point->getName()));

            return $this->redirectToRoute('pro_delivery_point_index');
        }

        return $this->render('delivery/point/form.html.twig', ['point' => $point, 'form' => $form]);
    }

    /**
     * Archives an active delivery point, or brings an archived one back.
     */
    #[Route('/{id}/archiver', name: 'pro_delivery_point_archive', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function archive(int $id, Request $request): RedirectResponse
    {
        $point = $this->findPoint($id);

        if (!$this->isCsrfTokenValid('delivery_point_archive_'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $point->setArchived(!$point->isArchived());
        $this->entityManager->flush();

        $this->addFlash('success', $point->isArchived()
            ? \sprintf('« %s » est archivé : il ne sera plus proposé dans tes nouvelles ventes.', $point->getName())
            : \sprintf('« %s » est de nouveau disponible.', $point->getName()));

        return $this->redirectToRoute('pro_delivery_point_index');
    }

    private function findPoint(int $id): DeliveryPoint
    {
        return $this->deliveryPoints->findOneInShop($id, $this->currentShop->get())
            ?? throw $this->createNotFoundException('Delivery point not found in the current shop.');
    }
}
