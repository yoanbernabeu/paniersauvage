<?php

declare(strict_types=1);

namespace App\Shop\Controller;

use App\Instance\InstanceSettings;
use App\Shop\Entity\User;
use App\Shop\Form\Model\RegistrationData;
use App\Shop\Form\RegistrationType;
use App\Shop\Service\ProducerRegistrar;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

final class RegistrationController extends AbstractController
{
    #[Route('/pro/inscription', name: 'pro_register', methods: ['GET', 'POST'])]
    public function __invoke(
        Request $request,
        ProducerRegistrar $registrar,
        Security $security,
        RateLimiterFactoryInterface $registrationLimiter,
        InstanceSettings $instance,
    ): Response {
        if ($this->getUser() instanceof User) {
            return $this->redirectToRoute('pro_onboarding');
        }

        $data = new RegistrationData();
        $form = $this->createForm(RegistrationType::class, $data);
        $form->handleRequest($request);

        // "Un compte existe déjà avec cette adresse" (UniqueEntity on User) tells whether a producer
        // e-mail is registered. Kept on purpose: without e-mail verification at sign-up, hiding it
        // would leave a producer who forgot their account with no explanation. The risk stays low
        // because every submission, valid or not, is counted by this limiter before validation runs
        // (10 per IP every 30 minutes), and producer addresses are mostly public business contacts.
        if ($form->isSubmitted() && !$registrationLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            // A fresh form: the validation errors of the submitted one would still answer the question.
            $form = $this->createForm(RegistrationType::class, $data);
            $form->addError(new FormError('Trop de tentatives d\'inscription. Réessaie dans quelques minutes.'));

            return $this->render('shop/registration/register.html.twig', ['form' => $form], new Response(status: Response::HTTP_TOO_MANY_REQUESTS));
        }

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $user = $registrar->register($data);
            } catch (UniqueConstraintViolationException) {
                // Two sign-ups raced for the same e-mail or shop address.
                $form->addError(new FormError('Ce compte vient d\'être créé. Connecte-toi, ou réessaie dans un instant.'));

                return $this->render('shop/registration/register.html.twig', ['form' => $form]);
            }

            $security->login($user, 'form_login', 'producer');
            $this->addFlash('success', \sprintf('Bienvenue %s ! Ta boutique est créée, %s.', $user->getFirstName(), $instance->isSponsored() ? 'ton abonnement est offert par '.$instance->carrierName : 'tu as 30 jours d\'essai gratuit'));

            return $this->redirectToRoute('pro_onboarding');
        }

        return $this->render('shop/registration/register.html.twig', ['form' => $form]);
    }
}
