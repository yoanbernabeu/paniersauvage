<?php

declare(strict_types=1);

namespace App\Legal\Controller;

use App\Legal\Enum\ReportReason;
use App\Legal\Event\ShopReported;
use App\Legal\Form\ShopReportType;
use App\Shop\Repository\ShopRepository;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public report of a shop, e-mailed to the operator (ShopReported). Protected by the form's CSRF
 * token, a honeypot field (bots are dropped silently) and a limit per IP. Under `/{slug}`: declared
 * with the low priority of the shop pages, so it never shadows another route.
 */
final class ShopReportController extends AbstractController
{
    #[Route('/{slug}/signaler', name: 'shop_report', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'], methods: ['GET', 'POST'], priority: -100)]
    public function __invoke(
        string $slug,
        Request $request,
        ShopRepository $shops,
        EventDispatcherInterface $eventDispatcher,
        ClockInterface $clock,
        RateLimiterFactoryInterface $shopReportIpLimiter,
    ): Response {
        $shop = $shops->findOneBySlug($slug) ?? throw $this->createNotFoundException('Unknown shop.');
        $shopUrl = $this->generateUrl('shop_show', ['slug' => $shop->getSlug()]);

        $form = $this->createForm(ShopReportType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $honeypot = $form->get(ShopReportType::HONEYPOT_FIELD)->getData();
            if (\is_string($honeypot) && '' !== trim($honeypot)) {
                // Only bots fill the hidden field: pretend it worked.
                $this->addFlash('success', 'Merci, ton signalement est bien parti.');

                return $this->redirect($shopUrl, Response::HTTP_SEE_OTHER);
            }

            if (!$shopReportIpLimiter->create($request->getClientIp())->consume()->isAccepted()) {
                $form->addError(new FormError('Tu as envoyé plusieurs signalements d\'affilée. Réessaie dans une heure, ou écris-nous directement.'));

                return $this->render('legal/report.html.twig', ['shop' => $shop, 'form' => $form], new Response(status: Response::HTTP_TOO_MANY_REQUESTS));
            }
        }

        if ($form->isSubmitted() && $form->isValid()) {
            // The form trims every field and turns an empty e-mail into null.
            /** @var array{reason: ReportReason, message: string, email: ?string} $data */
            $data = $form->getData();
            $email = null === $data['email'] ? null : strtolower($data['email']);

            $eventDispatcher->dispatch(new ShopReported($shop, $data['reason'], $data['message'], $email, $clock->now()));
            $this->addFlash('success', 'Merci, ton signalement est bien parti. On l\'examine rapidement.');

            return $this->redirect($shopUrl, Response::HTTP_SEE_OTHER);
        }

        $response = $this->render('legal/report.html.twig', [
            'shop' => $shop,
            'form' => $form,
        ], $form->isSubmitted() ? new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY) : null);
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }
}
