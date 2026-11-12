<?php

declare(strict_types=1);

namespace App\Customer\Controller;

use App\Customer\Entity\Customer;
use App\Customer\Exception\PendingDeliveryException;
use App\Customer\Form\Model\OfflineCustomerData;
use App\Customer\Form\OfflineCustomerType;
use App\Customer\Repository\CustomerRepository;
use App\Customer\Service\CustomerEraser;
use App\Customer\Service\OfflineCustomerManager;
use App\Ordering\Repository\OrderRepository;
use App\Shop\Service\CurrentShop;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Producer side: a customer without e-mail of the current shop (order taken by phone). The
 * producer corrects them, gives them an address, or erases their personal data. Any other
 * customer — another shop's, or a regular account — answers 404.
 */
#[Route('/pro/clients-sans-email/{id}', requirements: ['id' => '\d+'])]
#[IsGranted('ROLE_PRODUCER')]
final class OfflineCustomerController extends AbstractController
{
    public function __construct(
        private readonly CurrentShop $currentShop,
        private readonly CustomerRepository $customers,
        private readonly OrderRepository $orders,
    ) {
    }

    #[Route('', name: 'pro_offline_customer_edit', methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request, OfflineCustomerManager $manager): Response
    {
        $customer = $this->find($id);
        $data = OfflineCustomerData::fromCustomer($customer);
        $form = $this->createForm(OfflineCustomerType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $back = $this->backUrl($customer);
            $holder = $manager->update($customer, $data->firstName, $data->lastName, $data->phone, $data->email);

            if ($holder !== $customer) {
                $this->addFlash('success', 'Un compte utilise déjà cette adresse : les commandes de ce client y sont rattachées.');
            } elseif ($holder->isWithoutEmail()) {
                $this->addFlash('success', \sprintf('Coordonnées de %s enregistrées.', $holder->getDisplayName()));

                return $this->redirectToRoute('pro_offline_customer_edit', ['id' => $id], Response::HTTP_SEE_OTHER);
            } else {
                $this->addFlash('success', \sprintf('%s a maintenant un compte : il recevra les prochains e-mails.', $holder->getDisplayName()));
            }

            return $this->redirect($back, Response::HTTP_SEE_OTHER);
        }

        return $this->render('customer/pro/offline_customer.html.twig', [
            'customer' => $customer,
            'orders' => $this->orders->findByCustomer($customer),
            'form' => $form,
        ], $form->isSubmitted() ? new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY) : null);
    }

    #[Route('/effacer', name: 'pro_offline_customer_erase', methods: ['POST'])]
    public function erase(int $id, Request $request, CustomerEraser $eraser): RedirectResponse
    {
        $customer = $this->find($id);

        if (!$this->isCsrfTokenValid('pro_offline_customer_erase_'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $back = $this->backUrl($customer);
        try {
            $eraser->erase($customer);
            $this->addFlash('success', 'Les données personnelles de ce client sont effacées.');
        } catch (PendingDeliveryException) {
            $this->addFlash('error', 'Ce client a encore une commande à livrer : livre-la ou annule-la avant d\'effacer ses données.');

            return $this->redirectToRoute('pro_offline_customer_edit', ['id' => $id], Response::HTTP_SEE_OTHER);
        }

        return $this->redirect($back, Response::HTTP_SEE_OTHER);
    }

    private function find(int $id): Customer
    {
        return $this->customers->findWithoutEmailInShop($id, $this->currentShop->get()) ?? throw $this->createNotFoundException('Unknown customer.');
    }

    /**
     * The sale of the customer's latest order, or the list of sales.
     */
    private function backUrl(Customer $customer): string
    {
        $latest = $this->orders->findByCustomer($customer)[0] ?? null;

        return null === $latest
            ? $this->generateUrl('pro_sale_index')
            : $this->generateUrl('pro_sale_show', ['id' => $latest->getSale()->getId()]);
    }
}
