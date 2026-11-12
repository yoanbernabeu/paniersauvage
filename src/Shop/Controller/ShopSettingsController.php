<?php

declare(strict_types=1);

namespace App\Shop\Controller;

use App\Shop\Form\ShopSettingsType;
use App\Shop\Service\CurrentShop;
use App\Shop\Service\ImageStorage;
use App\Shop\Service\OnboardingNudge;
use App\Shop\Service\ShopLink;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ShopSettingsController extends AbstractController
{
    #[Route('/pro/boutique', name: 'pro_shop_settings', methods: ['GET', 'POST'])]
    public function __invoke(
        Request $request,
        CurrentShop $currentShop,
        EntityManagerInterface $entityManager,
        ImageStorage $imageStorage,
        ShopLink $shopLink,
        OnboardingNudge $onboardingNudge,
    ): Response {
        $shop = $currentShop->get();
        $form = $this->createForm(ShopSettingsType::class, $shop, ['has_logo' => null !== $shop->getLogoPath()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $previousLogo = $shop->getLogoPath();
            $logo = $form->get('logo')->getData();

            if ($logo instanceof UploadedFile) {
                $shop->setLogoPath($imageStorage->store($logo, 'shops/'.$shop->getId()));
            } elseif ($form->has('removeLogo') && true === $form->get('removeLogo')->getData()) {
                $shop->setLogoPath(null);
            }

            $entityManager->flush();

            if ($previousLogo !== $shop->getLogoPath()) {
                $imageStorage->remove($previousLogo);
            }

            $this->addFlash('success', 'Ta boutique est à jour.');
            $onboardingNudge->suggestNextStep($shop);

            return $this->redirectToRoute('pro_shop_settings');
        }

        if ($form->isSubmitted()) {
            // The form wrote the invalid values into the managed entity: do not let the layout show them.
            $entityManager->refresh($shop);
        }

        return $this->render('shop/settings/edit.html.twig', [
            'shop' => $shop,
            'form' => $form,
            'public_url' => $shopLink->publicUrl($shop),
            'share_url' => $shopLink->shareUrl($shop),
            'whatsapp_url' => $shopLink->whatsAppUrl($shop),
        ]);
    }
}
