<?php

declare(strict_types=1);

namespace App\Shop\Service;

use App\Billing\Entity\ShopSubscription;
use App\Legal\LegalDocuments;
use App\Shop\Entity\Shop;
use App\Shop\Entity\User;
use App\Shop\Form\Model\RegistrationData;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Sign-up of a producer: account, shop and free trial, in one transaction.
 */
final readonly class ProducerRegistrar
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private ShopSlugGenerator $slugGenerator,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws UniqueConstraintViolationException when the e-mail or the slug was taken in the meantime
     */
    public function register(RegistrationData $data): User
    {
        $shop = new Shop();
        $shop->setName($data->shopName);
        $shop->setSlug($this->slugGenerator->generate($data->shopName));

        $user = new User($data->email);
        $user->setFirstName($data->firstName);
        $user->setLastName($data->lastName);
        $user->setPassword($this->passwordHasher->hashPassword($user, $data->plainPassword));
        // The form refuses a sign-up without the box ticked.
        $user->acceptTerms(LegalDocuments::TERMS_VERSION, $this->clock->now());
        $shop->addUser($user);

        $this->entityManager->persist($shop);
        $this->entityManager->persist($user);
        $this->entityManager->persist(ShopSubscription::startTrial($shop, $this->clock->now(), ShopSubscription::TRIAL_DAYS));
        $this->entityManager->flush();

        return $user;
    }
}
