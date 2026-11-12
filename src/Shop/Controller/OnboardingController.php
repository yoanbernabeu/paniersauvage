<?php

declare(strict_types=1);

namespace App\Shop\Controller;

use App\Shop\Service\CurrentShop;
use App\Shop\Service\OnboardingChecklist;
use App\Shop\Service\ShopLink;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class OnboardingController extends AbstractController
{
    #[Route('/pro/bienvenue', name: 'pro_onboarding', methods: ['GET'])]
    public function __invoke(CurrentShop $currentShop, OnboardingChecklist $checklist, ShopLink $shopLink): Response
    {
        $shop = $currentShop->get();

        return $this->render('shop/onboarding/index.html.twig', [
            'shop' => $shop,
            'progress' => $checklist->progress($shop),
            'public_url' => $shopLink->publicUrl($shop),
            'share_url' => $shopLink->shareUrl($shop),
            'whatsapp_url' => $shopLink->whatsAppUrl($shop),
        ]);
    }
}
