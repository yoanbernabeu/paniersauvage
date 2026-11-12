<?php

declare(strict_types=1);

namespace App\Accounting\Controller;

use App\Accounting\Form\AccountingSettingsType;
use App\Accounting\Repository\AccountingSettingsRepository;
use App\Shop\Service\CurrentShop;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Accounts used by the accounting entries export of the current shop (no id in the request).
 */
final class AccountingSettingsController extends AbstractController
{
    #[Route('/pro/comptabilite/comptes', name: 'pro_accounting_settings', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, CurrentShop $currentShop, AccountingSettingsRepository $repository, EntityManagerInterface $entityManager): Response
    {
        $settings = $repository->forShop($currentShop->get());
        $form = $this->createForm(AccountingSettingsType::class, $settings);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($settings);
            $entityManager->flush();
            $this->addFlash('success', 'Tes comptes sont enregistrés. Tes prochains exports les utiliseront.');

            return $this->redirectToRoute('pro_accounting', status: Response::HTTP_SEE_OTHER);
        }

        return $this->render('accounting/settings.html.twig', [
            'form' => $form,
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
