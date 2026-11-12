<?php

declare(strict_types=1);

namespace App\Ordering\Controller;

use App\Ordering\Entity\Order;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTP response carrying an order's calendar file.
 */
final class IcsResponse
{
    public static function create(string $ics, Order $order): Response
    {
        $filename = \sprintf('livraison-%s.ics', mb_strtolower($order->getReference()));

        return new Response($ics, Response::HTTP_OK, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename),
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
