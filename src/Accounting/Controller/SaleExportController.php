<?php

declare(strict_types=1);

namespace App\Accounting\Controller;

use App\Accounting\Http\DownloadResponse;
use App\Accounting\Pdf\PdfRenderer;
use App\Accounting\Service\SaleExportBuilder;
use App\Sale\Entity\Sale;
use App\Sale\Repository\SaleRepository;
use App\Shop\Service\CurrentShop;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Exports of one sale. Every action starts from the current shop: a sale of another shop is a 404.
 */
#[Route('/pro/ventes/{id}', requirements: ['id' => '\d+'], methods: ['GET'])]
final class SaleExportController extends AbstractController
{
    public function __construct(
        private readonly CurrentShop $currentShop,
        private readonly SaleRepository $sales,
        private readonly SaleExportBuilder $exports,
        private readonly PdfRenderer $pdf,
    ) {
    }

    #[Route('/production.pdf', name: 'pro_sale_production_pdf')]
    public function production(int $id): Response
    {
        $sale = $this->findSale($id);

        return DownloadResponse::pdf(
            $this->pdf->render('accounting/pdf/production.html.twig', ['sheet' => $this->exports->productionSheet($sale)]),
            \sprintf('production-%s.pdf', $this->slug($sale)),
        );
    }

    #[Route('/livraison.pdf', name: 'pro_sale_delivery_pdf')]
    public function delivery(int $id): Response
    {
        $sale = $this->findSale($id);

        return DownloadResponse::pdf(
            $this->pdf->render('accounting/pdf/delivery.html.twig', ['sheet' => $this->exports->deliverySheet($sale)]),
            \sprintf('livraison-%s.pdf', $this->slug($sale)),
        );
    }

    #[Route('/commandes.csv', name: 'pro_sale_orders_csv')]
    public function orders(int $id): Response
    {
        $sale = $this->findSale($id);

        return DownloadResponse::csv($this->exports->ordersCsv($sale), \sprintf('commandes-%s.csv', $this->slug($sale)));
    }

    private function findSale(int $id): Sale
    {
        return $this->sales->findOneInShop($id, $this->currentShop->get()) ?? throw $this->createNotFoundException('Sale not found in the current shop.');
    }

    private function slug(Sale $sale): string
    {
        return \sprintf('%s-%s', $sale->getDeliveryDate()->format('Y-m-d'), strtolower($sale->getCode()));
    }
}
