<?php

declare(strict_types=1);

namespace App\Customer\Controller;

use App\Accounting\Http\DownloadResponse;
use App\Customer\Enum\CustomerSort;
use App\Customer\Model\ShopCustomer;
use App\Customer\Repository\CustomerRepository;
use App\Customer\Service\CustomerCsvExporter;
use App\Customer\Service\ShopCustomers;
use App\Ordering\Repository\OrderRepository;
use App\Shop\Service\CurrentShop;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Mes clients": the customers of the current shop, their orders there, and the CSV export. A
 * customer of another shop only answers 404, and their orders elsewhere are never shown (SPEC §2).
 */
#[Route('/pro/clients')]
#[IsGranted('ROLE_PRODUCER')]
final class ProCustomerController extends AbstractController
{
    public function __construct(
        private readonly CurrentShop $currentShop,
        private readonly ShopCustomers $shopCustomers,
    ) {
    }

    #[Route('', name: 'pro_customer_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $search = trim($request->query->getString('q'));
        $sort = CustomerSort::tryFrom($request->query->getString('tri')) ?? CustomerSort::Name;
        $customers = $this->shopCustomers->list($this->currentShop->get(), $search, $sort);

        return $this->render('customer/pro/index.html.twig', [
            'customers' => $customers,
            'search' => $search,
            'sort' => $sort,
            'sorts' => CustomerSort::cases(),
            'followers' => \count(array_filter($customers, static fn (ShopCustomer $customer): bool => $customer->follower)),
        ]);
    }

    #[Route('.csv', name: 'pro_customer_csv', methods: ['GET'])]
    public function csv(CustomerCsvExporter $exporter, ClockInterface $clock): Response
    {
        $shop = $this->currentShop->get();

        return DownloadResponse::csv(
            $exporter->export($this->shopCustomers->list($shop)),
            \sprintf('clients-%s-%s.csv', $shop->getSlug(), $clock->now()->format('Y-m-d')),
        );
    }

    #[Route('/{id}', name: 'pro_customer_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id, CustomerRepository $customers, OrderRepository $orders): Response
    {
        $shop = $this->currentShop->get();
        $customer = $customers->find($id) ?? throw $this->createNotFoundException('Unknown customer.');
        $row = $this->shopCustomers->find($shop, $id) ?? throw $this->createNotFoundException('Not a customer of this shop.');

        return $this->render('customer/pro/show.html.twig', [
            'row' => $row,
            'customer' => $customer,
            // Only possible for this shop's own customers without e-mail (ShopCustomers::find()).
            'offline' => $customer->isWithoutEmail(),
            'orders' => $orders->findByCustomerInShop($customer, $shop),
        ]);
    }
}
