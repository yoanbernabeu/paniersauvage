<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Customer\Entity\Customer;
use App\Legal\LegalDocuments;
use App\Shop\Entity\Shop;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Default: a regular account with an e-mail address. withoutEmail($shop): a customer without
 * e-mail, known by that shop only (order taken by phone).
 *
 * @extends PersistentObjectFactory<Customer>
 */
final class CustomerFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Customer::class;
    }

    public function withoutEmail(Shop $shop): static
    {
        return $this->instantiateWith(static fn (array $parameters): Customer => Customer::withoutEmail($shop, new \DateTimeImmutable())
            ->setFirstName(\is_string($parameters['firstName'] ?? null) ? $parameters['firstName'] : null)
            ->setLastName(\is_string($parameters['lastName'] ?? null) ? $parameters['lastName'] : null)
            ->setPhone(\is_string($parameters['phone'] ?? null) ? $parameters['phone'] : null));
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'email' => self::faker()->unique()->safeEmail(),
            'firstName' => self::faker()->firstName(),
            'lastName' => self::faker()->lastName(),
            'phone' => '06 12 34 56 78',
            // Accepted at sign-up: only guests tick the box on the order form.
            'termsVersion' => LegalDocuments::TERMS_VERSION,
            'termsAcceptedAt' => new \DateTimeImmutable('-1 month'),
        ];
    }
}
