<?php

declare(strict_types=1);

namespace App\Billing\Stripe;

/**
 * The webhook events that change a ShopSubscription. Any other type is acknowledged and ignored.
 */
enum BillingEventType: string
{
    case CheckoutCompleted = 'checkout.session.completed';
    case SubscriptionCreated = 'customer.subscription.created';
    case SubscriptionUpdated = 'customer.subscription.updated';
    case SubscriptionDeleted = 'customer.subscription.deleted';
    case InvoicePaymentFailed = 'invoice.payment_failed';
    case InvoicePaid = 'invoice.paid';
}
