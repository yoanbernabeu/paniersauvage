<?php

declare(strict_types=1);

namespace App\Ordering\Twig;

use App\Customer\Entity\Customer;
use App\Ordering\Service\NewCustomerPolicy;
use App\Shop\Entity\Shop;
use Twig\Attribute\AsTwigFunction;

/**
 * What a new customer is told about the shop's protection (NewCustomerPolicy): the cap of a first
 * order and the deposit asked outside the platform. Nothing for a customer who already picked up an
 * order there.
 */
final readonly class NewCustomerExtension
{
    public function __construct(
        private NewCustomerPolicy $policy,
    ) {
    }

    /**
     * {{ new_customer_deposit(shop, customer) }} — the deposit text, or null.
     */
    #[AsTwigFunction('new_customer_deposit')]
    public function deposit(Shop $shop, ?Customer $customer): ?string
    {
        $deposit = $shop->getNewCustomerDeposit();

        return null !== $deposit && $this->policy->isNewCustomer($shop, $customer) ? $deposit : null;
    }

    /**
     * {{ first_order_cap(shop, customer) }} — the cap of a first order in cents, or null.
     */
    #[AsTwigFunction('first_order_cap')]
    public function firstOrderCap(Shop $shop, ?Customer $customer): ?int
    {
        $cap = $shop->getFirstOrderCapCents();

        return null !== $cap && $this->policy->isNewCustomer($shop, $customer) ? $cap : null;
    }
}
