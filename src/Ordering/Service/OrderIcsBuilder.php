<?php

declare(strict_types=1);

namespace App\Ordering\Service;

use App\Ordering\Entity\Order;
use App\Shared\Service\FrenchFormatter;

/**
 * Builds the calendar file (.ics, RFC 5545) of an order's delivery slot.
 */
final readonly class OrderIcsBuilder
{
    private const string EOL = "\r\n";

    public function __construct(
        private FrenchFormatter $formatter,
    ) {
    }

    public function build(Order $order): string
    {
        $delivery = $order->getSaleDelivery();
        $point = $delivery->getDeliveryPoint();
        $shop = $order->getShop();

        $location = $delivery->isHome()
            ? ($order->getDeliveryAddress() ?? $point->getName())
            : trim($point->getName().', '.($point->getAddress() ?? ''), ', ');

        $description = [];
        foreach ($order->getLines() as $line) {
            $description[] = \sprintf('%s %s', $this->formatter->lineQuantity($line->getQuantity(), $line->getUnit()), $line->getLabel());
        }
        $description[] = \sprintf('À régler à la livraison : %s', $this->formatter->money($order->getTotalCents()));
        if (null !== $shop->getPaymentInfo() && '' !== trim($shop->getPaymentInfo())) {
            $description[] = \sprintf('Moyens de paiement acceptés : %s', trim($shop->getPaymentInfo()));
        }
        if (null !== $point->getInstructions() && '' !== trim($point->getInstructions())) {
            $description[] = trim($point->getInstructions());
        }
        $description[] = \sprintf('Commande %s', $order->getReference());

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Panier Sauvage//Commandes//FR',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:'.mb_strtolower($order->getReference()).'@panier-sauvage',
            'DTSTAMP:'.$this->utc($order->getUpdatedAt()),
            'DTSTART:'.$this->utc($delivery->getStartsAt()),
            'DTEND:'.$this->utc($delivery->getEndsAt()),
            'SUMMARY:'.$this->escape(\sprintf('Livraison %s', $shop->getName())),
            'LOCATION:'.$this->escape($location),
            'DESCRIPTION:'.$this->escape(implode("\n", $description)),
            'STATUS:'.($order->isCancelled() ? 'CANCELLED' : 'CONFIRMED'),
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        return implode(self::EOL, array_map($this->fold(...), $lines)).self::EOL;
    }

    private function utc(\DateTimeImmutable $date): string
    {
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }

    private function escape(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        // Narrow and regular no-break spaces confuse some calendar apps.
        $text = str_replace(["\u{202F}", "\u{00A0}"], ' ', $text);

        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\;', '\,', '\n'], $text);
    }

    /**
     * Folds a content line at 75 octets without cutting a multi-byte character.
     */
    private function fold(string $line): string
    {
        $chunks = [];
        $current = '';

        foreach (mb_str_split($line) as $char) {
            $limit = [] === $chunks ? 75 : 74;
            if (\strlen($current.$char) > $limit) {
                $chunks[] = $current;
                $current = '';
            }
            $current .= $char;
        }
        $chunks[] = $current;

        return implode(self::EOL.' ', $chunks);
    }
}
