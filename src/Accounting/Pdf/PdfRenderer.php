<?php

declare(strict_types=1);

namespace App\Accounting\Pdf;

use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Twig\Environment;

/**
 * Turns a Twig template into an A4 PDF with dompdf.
 *
 * Templates must stay simple (tables, no flexbox, no grid) and only use the DejaVu fonts
 * shipped with dompdf, which are embedded in the file. Nothing is fetched from the network.
 */
final readonly class PdfRenderer
{
    public const string FONT = 'DejaVu Sans';

    public function __construct(
        private Environment $twig,
        #[Autowire('%kernel.cache_dir%/dompdf')]
        private string $cacheDir,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return string the binary content of the PDF
     */
    public function render(string $template, array $context = []): string
    {
        new Filesystem()->mkdir($this->cacheDir);

        $options = new Options();
        $options->setDefaultFont(self::FONT);
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setIsFontSubsettingEnabled(true);
        $options->setFontCache($this->cacheDir);
        $options->setTempDir($this->cacheDir);
        $options->setChroot($this->cacheDir);

        $dompdf = new Dompdf($options);
        $dompdf->setPaper('A4');
        $dompdf->loadHtml($this->twig->render($template, $context), 'UTF-8');
        $dompdf->render();

        $font = $dompdf->getFontMetrics()->getFont(self::FONT);
        if (null !== $font) {
            $canvas = $dompdf->getCanvas();
            $canvas->page_text($canvas->get_width() - 90, $canvas->get_height() - 28, 'Page {PAGE_NUM} / {PAGE_COUNT}', $font, 8, [0.34, 0.39, 0.36]);
        }

        $pdf = $dompdf->output();
        if ('' === $pdf) {
            throw new \RuntimeException(\sprintf('dompdf produced no output for "%s".', $template));
        }

        return $pdf;
    }
}
