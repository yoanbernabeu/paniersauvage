<?php

declare(strict_types=1);

namespace App\Legal\Controller;

use App\Legal\Form\TermsAcceptanceType;
use App\Legal\LegalDocuments;
use App\Shop\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Acceptance of a new version of the terms by a producer (TermsAcceptanceSubscriber leads here).
 */
final class TermsAcceptanceController extends AbstractController
{
    public const string ROUTE = 'pro_terms_accept';

    #[Route('/pro/conditions', name: self::ROUTE, methods: ['GET', 'POST'])]
    public function __invoke(Request $request, #[CurrentUser] User $user, EntityManagerInterface $entityManager, ClockInterface $clock): Response
    {
        $back = $this->localProPath($request->query->get('retour'));

        if ($user->hasAcceptedTerms(LegalDocuments::TERMS_VERSION)) {
            return $this->redirect($back ?? $this->generateUrl('pro_dashboard'));
        }

        $form = $this->createForm(TermsAcceptanceType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->acceptTerms(LegalDocuments::TERMS_VERSION, $clock->now());
            $entityManager->flush();
            $this->addFlash('success', 'Merci ! Tu peux reprendre là où tu en étais.');

            return $this->redirect($back ?? $this->generateUrl('pro_dashboard'), Response::HTTP_SEE_OTHER);
        }

        return $this->render('legal/terms_acceptance.html.twig', [
            'form' => $form,
            'first_time' => null === $user->getTermsVersion(),
            'version' => LegalDocuments::TERMS_VERSION,
            'updated_at' => new \DateTimeImmutable(LegalDocuments::TERMS_UPDATED_AT),
        ], $form->isSubmitted() ? new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY) : null);
    }

    /**
     * A path of the producer area to come back to, never another site.
     */
    private function localProPath(mixed $path): ?string
    {
        return \is_string($path) && 1 === preg_match('#^/pro(/[^/\\\\]|$|\?)#', $path) ? $path : null;
    }
}
