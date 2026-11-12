<?php

declare(strict_types=1);

namespace App\Catalog\Controller;

use App\Catalog\Dfc\DfcCatalogExporter;
use App\Catalog\Dfc\DfcVocabulary;
use App\Catalog\Repository\CatalogPublicationRepository;
use App\Shop\Entity\Shop;
use App\Shop\Repository\ShopRepository;
use App\Shop\Service\CurrentShop;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Catalogue in the Data Food Consortium format: a page and a download in /pro, and, when the
 * producer switches it on, a stable public URL other platforms can read.
 */
final class DfcCatalogController extends AbstractController
{
    public const string CSRF_PUBLISH = 'dfc_publish';
    public const string CONTENT_TYPE = 'application/ld+json; charset=UTF-8';

    public function __construct(
        private readonly DfcCatalogExporter $exporter,
        private readonly CatalogPublicationRepository $publications,
    ) {
    }

    #[Route('/pro/produits/data-food-consortium', name: 'pro_catalog_dfc', methods: ['GET'])]
    public function index(CurrentShop $currentShop): Response
    {
        $shop = $currentShop->get();

        return $this->render('catalog/dfc.html.twig', [
            'publication' => $this->publications->forShop($shop),
            'public_url' => $this->generateUrl('shop_dfc_catalog', ['slug' => $shop->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL),
            'context_url' => DfcVocabulary::CONTEXT,
        ]);
    }

    #[Route('/pro/produits/data-food-consortium/catalogue.jsonld', name: 'pro_catalog_dfc_download', methods: ['GET'])]
    public function download(CurrentShop $currentShop): JsonResponse
    {
        $shop = $currentShop->get();
        $response = $this->catalogResponse($shop);
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, \sprintf('catalogue-dfc-%s.jsonld', $shop->getSlug())));
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    #[Route('/pro/produits/data-food-consortium/publication', name: 'pro_catalog_dfc_publish', methods: ['POST'])]
    public function publish(Request $request, CurrentShop $currentShop, EntityManagerInterface $entityManager, ClockInterface $clock): RedirectResponse
    {
        if (!$this->isCsrfTokenValid(self::CSRF_PUBLISH, $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $public = '1' === $request->getPayload()->getString('public');
        $publication = $this->publications->forShop($currentShop->get())->setDfcPublic($public, $clock->now());
        $entityManager->persist($publication);
        $entityManager->flush();

        $this->addFlash('success', $public
            ? 'Ton catalogue est publié à son adresse publique. Tu peux la donner aux plateformes compatibles.'
            : 'Ton catalogue n\'est plus publié : son adresse publique ne répond plus.');

        return $this->redirectToRoute('pro_catalog_dfc', status: Response::HTTP_SEE_OTHER);
    }

    /**
     * Public catalogue of a shop that chose to publish it; 404 otherwise (as for an unknown shop).
     */
    #[Route('/{slug}/catalogue.jsonld', name: 'shop_dfc_catalog', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'], methods: ['GET'])]
    public function public(string $slug, ShopRepository $shops): JsonResponse
    {
        $shop = $shops->findOneBy(['slug' => $slug]);
        if (!$shop instanceof Shop || !$this->publications->isDfcPublic($shop)) {
            throw $this->createNotFoundException();
        }

        $response = $this->catalogResponse($shop);
        $response->setPublic();
        $response->setMaxAge(300);
        // Public data meant to be read by other platforms, from their servers or their pages.
        $response->headers->set('Access-Control-Allow-Origin', '*');

        return $response;
    }

    private function catalogResponse(Shop $shop): JsonResponse
    {
        $response = new JsonResponse($this->exporter->export($shop), Response::HTTP_OK, ['Content-Type' => self::CONTENT_TYPE, 'X-Content-Type-Options' => 'nosniff']);
        $response->setEncodingOptions(\JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR);

        return $response;
    }
}
