<?php

declare(strict_types=1);

namespace App\Shop\Enum;

/**
 * A means of payment a producer accepts, shown to customers. Never a payment record (SPEC §1):
 * the customer settles directly with the producer.
 */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case Cheque = 'cheque';
    case InstantTransfer = 'instant_transfer';
    case BankTransfer = 'bank_transfer';
    case CardOnSite = 'card_on_site';
    case LocalCurrency = 'local_currency';
    case FoodVoucher = 'food_voucher';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Espèces',
            self::Cheque => 'Chèque',
            self::InstantTransfer => 'Wero / paiement instantané',
            self::BankTransfer => 'Virement',
            self::CardOnSite => 'Carte sur place',
            self::LocalCurrency => 'Monnaie locale',
            self::FoodVoucher => 'Chèques ou caisse alimentaire',
        };
    }
}
