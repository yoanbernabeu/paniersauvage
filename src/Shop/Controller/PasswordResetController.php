<?php

declare(strict_types=1);

namespace App\Shop\Controller;

use App\Shop\Entity\PasswordResetRequest;
use App\Shop\Entity\User;
use App\Shop\Form\Model\NewPasswordData;
use App\Shop\Form\NewPasswordType;
use App\Shop\Form\PasswordForgottenType;
use App\Shop\Service\PasswordResetManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Mot de passe oublié ?" for producers.
 */
final class PasswordResetController extends AbstractController
{
    /** The token leaves the URL as soon as possible (no leak through history or Referer). */
    private const string TOKEN_SESSION_KEY = 'pro.password_reset_token';

    public function __construct(
        private readonly PasswordResetManager $manager,
        private readonly RateLimiterFactoryInterface $producerPasswordResetEmailLimiter,
        private readonly RateLimiterFactoryInterface $producerPasswordResetIpLimiter,
    ) {
    }

    #[Route('/pro/mot-de-passe-oublie', name: 'pro_password_forgot', methods: ['GET', 'POST'])]
    public function forgot(Request $request): Response
    {
        if ($this->getUser() instanceof User) {
            return $this->redirectToRoute('pro_dashboard');
        }

        $form = $this->createForm(PasswordForgottenType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $email = $form->get('email')->getData();
            $email = mb_strtolower(trim(\is_string($email) ? $email : ''));

            // Both limiters are consumed whatever the address: the answer never tells whether an account exists.
            $ipLimit = $this->producerPasswordResetIpLimiter->create($request->getClientIp() ?? 'unknown')->consume();
            $emailLimit = $this->producerPasswordResetEmailLimiter->create($email)->consume();

            if (!$ipLimit->isAccepted() || !$emailLimit->isAccepted()) {
                $form->addError(new FormError('Tu as fait beaucoup de demandes d\'un coup. Patiente quelques minutes avant de réessayer.'));

                return $this->render('shop/password/forgot.html.twig', ['form' => $form], new Response(status: Response::HTTP_TOO_MANY_REQUESTS));
            }

            $this->manager->request($email);

            return $this->redirectToRoute('pro_password_forgot_sent');
        }

        return $this->render('shop/password/forgot.html.twig', ['form' => $form]);
    }

    #[Route('/pro/mot-de-passe-oublie/envoye', name: 'pro_password_forgot_sent', methods: ['GET'])]
    public function sent(): Response
    {
        return $this->render('shop/password/sent.html.twig', ['minutes' => PasswordResetManager::LIFETIME_MINUTES]);
    }

    #[Route('/pro/mot-de-passe/nouveau/{token}', name: 'pro_password_reset_token', requirements: ['token' => '[A-Za-z0-9_-]{20,100}'], methods: ['GET'])]
    public function token(Request $request, string $token): RedirectResponse
    {
        $request->getSession()->set(self::TOKEN_SESSION_KEY, $token);

        return $this->redirectToRoute('pro_password_reset');
    }

    #[Route('/pro/mot-de-passe/nouveau', name: 'pro_password_reset', methods: ['GET', 'POST'])]
    public function reset(Request $request, Security $security): Response
    {
        $token = $request->hasPreviousSession() ? $request->getSession()->get(self::TOKEN_SESSION_KEY) : null;
        $resetRequest = \is_string($token) ? $this->manager->findUsable($token) : null;

        if (!$resetRequest instanceof PasswordResetRequest) {
            return $this->render('shop/password/invalid.html.twig', [], new Response(status: Response::HTTP_GONE));
        }

        $data = new NewPasswordData();
        $form = $this->createForm(NewPasswordType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user = $this->manager->reset($resetRequest, $data->plainPassword);
            $request->getSession()->remove(self::TOKEN_SESSION_KEY);

            $security->login($user, 'form_login', 'producer');
            $this->addFlash('success', 'C\'est fait : ton nouveau mot de passe est enregistré.');

            return $this->redirectToRoute('pro_dashboard');
        }

        return $this->render('shop/password/reset.html.twig', [
            'form' => $form,
            'email' => $resetRequest->getUser()->getEmail(),
        ]);
    }
}
