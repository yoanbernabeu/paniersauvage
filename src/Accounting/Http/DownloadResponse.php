<?php

declare(strict_types=1);

namespace App\Accounting\Http;

use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTP responses of the exports: never cached (they hold customer data), never sniffed.
 */
final class DownloadResponse
{
    /**
     * Shown in the browser's PDF viewer, ready to print.
     */
    public static function pdf(string $content, string $filename): Response
    {
        return self::create($content, 'application/pdf', HeaderUtils::DISPOSITION_INLINE, $filename);
    }

    /**
     * Downloaded, so that a double click opens it in the spreadsheet.
     */
    public static function csv(string $content, string $filename): Response
    {
        return self::create($content, 'text/csv; charset=UTF-8', HeaderUtils::DISPOSITION_ATTACHMENT, $filename);
    }

    /**
     * Plain text file (FEC format), downloaded.
     */
    public static function text(string $content, string $filename): Response
    {
        return self::create($content, 'text/plain; charset=UTF-8', HeaderUtils::DISPOSITION_ATTACHMENT, $filename);
    }

    private static function create(string $content, string $contentType, string $disposition, string $filename): Response
    {
        $response = new Response($content, Response::HTTP_OK, [
            'Content-Type' => $contentType,
            'Content-Disposition' => HeaderUtils::makeDisposition($disposition, $filename),
            'Content-Length' => (string) \strlen($content),
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
