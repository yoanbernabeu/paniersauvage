<?php

declare(strict_types=1);

namespace App\Legal\Controller;

use App\Legal\LegalDocuments;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public legal pages: legal notice (LCEN), terms of use (producers and customers, with the data
 * processing agreement and the withdrawal form), privacy policy, public commitments.
 */
final class LegalPageController extends AbstractController
{
    #[Route('/mentions-legales', name: 'legal_notice', methods: ['GET'])]
    public function notice(): Response
    {
        return $this->page('legal/pages/notice.html.twig', LegalDocuments::NOTICE_VERSION, LegalDocuments::NOTICE_UPDATED_AT);
    }

    #[Route('/cgu', name: 'legal_terms', methods: ['GET'])]
    public function terms(): Response
    {
        return $this->page('legal/pages/terms.html.twig', LegalDocuments::TERMS_VERSION, LegalDocuments::TERMS_UPDATED_AT);
    }

    #[Route('/confidentialite', name: 'legal_privacy', methods: ['GET'])]
    public function privacy(): Response
    {
        return $this->page('legal/pages/privacy.html.twig', LegalDocuments::PRIVACY_VERSION, LegalDocuments::PRIVACY_UPDATED_AT);
    }

    #[Route('/engagements', name: 'legal_commitments', methods: ['GET'])]
    public function commitments(): Response
    {
        return $this->page('legal/pages/commitments.html.twig', LegalDocuments::COMMITMENTS_VERSION, LegalDocuments::COMMITMENTS_UPDATED_AT);
    }

    private function page(string $template, string $version, string $updatedAt): Response
    {
        return $this->render($template, [
            'version' => $version,
            'updated_at' => new \DateTimeImmutable($updatedAt),
        ]);
    }
}
