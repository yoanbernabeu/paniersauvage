<?php

declare(strict_types=1);

namespace App\Shop\Service;

use App\Shop\Entity\Shop;
use App\Shop\Entity\User;
use App\Shop\Exception\NoCurrentShopException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Entry point of producer isolation: every `/pro` query starts from the shop returned here.
 */
#[Autoconfigure(public: true)]
final readonly class CurrentShop
{
    public function __construct(
        private Security $security,
    ) {
    }

    /**
     * @throws NoCurrentShopException when no producer is logged in or the producer has no shop
     */
    public function get(): Shop
    {
        return $this->find() ?? throw new NoCurrentShopException();
    }

    public function find(): ?Shop
    {
        return $this->getUser()?->getShop();
    }

    public function has(): bool
    {
        return $this->find() instanceof Shop;
    }

    public function getUser(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }

    public function owns(?Shop $shop): bool
    {
        $current = $this->find();

        if (!$shop instanceof Shop || !$current instanceof Shop) {
            return false;
        }

        return $shop === $current || (null !== $shop->getId() && $shop->getId() === $current->getId());
    }

    /**
     * Answers 404 when the given resource belongs to another shop (SPEC §1.3).
     *
     * @throws NotFoundHttpException
     */
    public function denyUnlessOwns(?Shop $shop): void
    {
        if (!$this->owns($shop)) {
            throw new NotFoundHttpException('This resource does not belong to the current shop.');
        }
    }
}
