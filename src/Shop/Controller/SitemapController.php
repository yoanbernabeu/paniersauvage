<?php

declare(strict_types=1);

namespace App\Shop\Controller;

use App\Shared\Navigation\SafeRouter;
use App\Shop\Repository\ShopRepository;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * sitemap.xml: the pages search engines may index. The home page, the directory, the legal pages
 * that exist, the town pages and the pages of the shops of the directory (opt-in): a shop that
 * did not choose to appear in the directory is never advertised here.
 */
final class SitemapController extends AbstractController
{
    /** Legal pages (lot B), listed only once their route exists. */
    public const array LEGAL_ROUTES = ['legal_notice', 'legal_terms', 'legal_privacy', 'legal_commitments'];

    #[Route('/sitemap.xml', name: 'sitemap', methods: ['GET'], format: 'xml')]
    public function __invoke(ShopRepository $shops, SafeRouter $router, ClockInterface $clock): Response
    {
        $now = $clock->now();
        $urls = [
            $this->generateUrl('home', [], UrlGeneratorInterface::ABSOLUTE_URL),
            $this->generateUrl('producer_directory', [], UrlGeneratorInterface::ABSOLUTE_URL),
        ];

        foreach (self::LEGAL_ROUTES as $route) {
            if ($router->exists($route)) {
                $urls[] = $this->generateUrl($route, [], UrlGeneratorInterface::ABSOLUTE_URL);
            }
        }

        foreach ($shops->findDirectoryCommunes($now) as $commune) {
            $urls[] = $this->generateUrl('producer_directory_commune', ['commune' => $commune->slug], UrlGeneratorInterface::ABSOLUTE_URL);
        }

        foreach ($shops->findInDirectory($now) as $shop) {
            $urls[] = $this->generateUrl('shop_show', ['slug' => $shop->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL);
        }

        $response = $this->render('shop/sitemap.xml.twig', ['urls' => $urls]);
        $response->headers->set('Content-Type', 'application/xml; charset=UTF-8');

        return $response;
    }
}
