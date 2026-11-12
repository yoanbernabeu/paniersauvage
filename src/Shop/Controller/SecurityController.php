<?php

declare(strict_types=1);

namespace App\Shop\Controller;

use App\Shop\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class SecurityController extends AbstractController
{
    /** Where a logged-in producer lands (`pro_dashboard`, created by another feature set). */
    private const string DASHBOARD_PATH = '/pro';

    #[Route('/pro/connexion', name: 'pro_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser() instanceof User) {
            return $this->redirect(self::DASHBOARD_PATH);
        }

        return $this->render('shop/security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }

    #[Route('/pro/deconnexion', name: 'pro_logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new \LogicException('This route is intercepted by the logout listener of the "producer" firewall.');
    }
}
