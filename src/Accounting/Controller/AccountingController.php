<?php

declare(strict_types=1);

namespace App\Accounting\Controller;

use App\Accounting\Http\DownloadResponse;
use App\Accounting\Model\SalesJournal;
use App\Accounting\Pdf\PdfRenderer;
use App\Accounting\Repository\AccountingSettingsRepository;
use App\Accounting\Service\AccountingEntriesBuilder;
use App\Accounting\Service\AccountingEntriesExporter;
use App\Accounting\Service\SalesJournalBuilder;
use App\Accounting\Service\SalesJournalCsvExporter;
use App\Shop\Service\CurrentShop;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Monthly sales journal of the current shop (delivered orders only) and its exports.
 * The month comes from the `mois` query parameter (`2026-11`); default: the current month.
 */
#[Route('/pro/comptabilite', methods: ['GET'])]
final class AccountingController extends AbstractController
{
    public function __construct(
        private readonly CurrentShop $currentShop,
        private readonly SalesJournalBuilder $journals,
        private readonly ClockInterface $clock,
        private readonly AccountingSettingsRepository $accounts,
    ) {
    }

    #[Route('', name: 'pro_accounting')]
    public function index(Request $request): Response
    {
        return $this->render('accounting/index.html.twig', [
            'journal' => $this->journal($request),
            'accounts' => $this->accounts->forShop($this->currentShop->get()),
            'current_month' => $this->clock->now()->modify('first day of this month')->setTime(0, 0),
        ]);
    }

    #[Route('/journal.csv', name: 'pro_accounting_csv')]
    public function csv(Request $request, SalesJournalCsvExporter $exporter): Response
    {
        $journal = $this->journal($request);

        return DownloadResponse::csv($exporter->export($journal), \sprintf('journal-des-ventes-%s.csv', $journal->month->format('Y-m')));
    }

    /**
     * Accounting entries of the month (sales journal VE), CSV to import in an accounting software.
     */
    #[Route('/ecritures.csv', name: 'pro_accounting_entries_csv')]
    public function entriesCsv(Request $request, AccountingEntriesBuilder $builder, AccountingEntriesExporter $exporter): Response
    {
        $shop = $this->currentShop->get();
        $month = $this->month($request);
        $accounts = $this->accounts->forShop($shop);

        return DownloadResponse::csv($exporter->csv($builder->build($shop, $month, $accounts), $accounts), \sprintf('ecritures-ventes-%s.csv', $month->format('Y-m')));
    }

    /**
     * The same entries with the 18 columns of the FEC format (draft entries to import).
     */
    #[Route('/ecritures-fec.txt', name: 'pro_accounting_fec')]
    public function entriesFec(Request $request, AccountingEntriesBuilder $builder, AccountingEntriesExporter $exporter): Response
    {
        $shop = $this->currentShop->get();
        $month = $this->month($request);
        $accounts = $this->accounts->forShop($shop);

        return DownloadResponse::text($exporter->fec($builder->build($shop, $month, $accounts), $accounts, $month), \sprintf('ecritures-ventes-%s-format-fec.txt', $month->format('Y-m')));
    }

    #[Route('/journal.pdf', name: 'pro_accounting_pdf')]
    public function pdf(Request $request, PdfRenderer $pdf): Response
    {
        $shop = $this->currentShop->get();
        $journal = $this->journals->build($shop, $this->month($request));

        return DownloadResponse::pdf(
            $pdf->render('accounting/pdf/journal.html.twig', ['journal' => $journal, 'shop' => $shop, 'generated_at' => $this->clock->now()]),
            \sprintf('journal-des-ventes-%s.pdf', $journal->month->format('Y-m')),
        );
    }

    private function journal(Request $request): SalesJournal
    {
        return $this->journals->build($this->currentShop->get(), $this->month($request));
    }

    /**
     * `mois=2026-11`, or the `annee` + `mois_numero` pair sent by the month picker. A missing or
     * malformed value falls back to the current month.
     */
    private function month(Request $request): \DateTimeImmutable
    {
        $value = $request->query->getString('mois');
        $now = $this->clock->now();

        if ('' === $value && $request->query->has('annee')) {
            $value = $request->query->getString('annee').'-'.$request->query->getString('mois_numero');
        }

        if (1 === preg_match('/^(20\d{2})-(0[1-9]|1[0-2])$/D', $value, $matches)) {
            return $now->setDate((int) $matches[1], (int) $matches[2], 1)->setTime(0, 0);
        }

        return $now->modify('first day of this month')->setTime(0, 0);
    }
}
