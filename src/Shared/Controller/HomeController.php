<?php

declare(strict_types=1);

namespace App\Shared\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Home page: Panier Sauvage presented to producers (what it does, the price, the commitments, the
 * sign-up). Customers looking for a producer are sent to the directory (`producer_directory`).
 */
final class HomeController extends AbstractController
{
    #[Route('/', name: 'home', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('home/index.html.twig');
    }
}
