<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Legal\LegalDocuments;
use App\Shop\Entity\User;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Producers are created with the password "password" and their own shop.
 *
 * @extends PersistentObjectFactory<User>
 */
final class UserFactory extends PersistentObjectFactory
{
    public const string PASSWORD = 'password';

    /** Bcrypt hash of "password" (cost 4: fast, for tests and the demo data set only). */
    private const string PASSWORD_HASH = '$2y$04$FN9BCeq1A2lphpzspRp4UeUKWo9yapYJHgG33DBFc/VirpbDquEwq';

    public static function class(): string
    {
        return User::class;
    }

    /**
     * A producer who has not created a shop yet.
     */
    public function withoutShop(): static
    {
        return $this->with(['shop' => null]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'email' => self::faker()->unique()->safeEmail(),
            'password' => self::PASSWORD_HASH,
            'firstName' => self::faker()->firstName(),
            'lastName' => self::faker()->lastName(),
            'shop' => ShopFactory::new(),
            // Accepted at sign-up: otherwise every /pro page leads to the terms first.
            'termsVersion' => LegalDocuments::TERMS_VERSION,
            'termsAcceptedAt' => new \DateTimeImmutable('-1 month'),
        ];
    }
}
