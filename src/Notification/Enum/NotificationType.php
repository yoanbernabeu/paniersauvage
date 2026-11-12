<?php

declare(strict_types=1);

namespace App\Notification\Enum;

/**
 * Every e-mail the platform sends. The value is stored in NotificationLog::$type and names the templates
 * (`templates/notification/email/<value>.{html,txt}.twig`).
 */
enum NotificationType: string
{
    case OrderPlaced = 'order_placed';
    case OrderUpdated = 'order_updated';
    case OrderCancelled = 'order_cancelled';
    case OrderReminder = 'order_reminder';
    case SaleOpened = 'sale_opened';
    case SaleClosed = 'sale_closed';
    case SaleCancelled = 'sale_cancelled';
    case SolidarityRenewal = 'solidarity_renewal';
    case LastMinuteSale = 'last_minute_sale';
    // Recurring orders (App\Recurring, SPEC « lot H »).
    case RecurringStarted = 'recurring_started';
    case RecurringOrderGenerated = 'recurring_order_generated';
    case RecurringSuspended = 'recurring_suspended';
    case RecurringEnded = 'recurring_ended';
    case RecurringShortages = 'recurring_shortages';

    public function label(): string
    {
        return match ($this) {
            self::OrderPlaced => 'Confirmation de commande',
            self::OrderUpdated => 'Commande modifiée',
            self::OrderCancelled => 'Commande annulée',
            self::OrderReminder => 'Rappel de la veille',
            self::SaleOpened => 'Ouverture des commandes',
            self::SaleClosed => 'Récapitulatif de clôture',
            self::SaleCancelled => 'Vente annulée',
            self::SolidarityRenewal => 'Renouvellement du palier solidaire',
            self::LastMinuteSale => 'Vente de dernière minute',
            self::RecurringStarted => 'Confirmation d\'abonnement',
            self::RecurringOrderGenerated => 'Commande d\'abonnement',
            self::RecurringSuspended => 'Abonnement suspendu',
            self::RecurringEnded => 'Fin d\'abonnement',
            self::RecurringShortages => 'Abonnements incomplets',
        };
    }
}
