<?php

declare(strict_types=1);

namespace App\Shop\Exception;

use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Thrown when a producer-side service needs the current shop but nobody is logged in as a
 * producer, or the producer has no shop yet. As an access-denied error it sends anonymous
 * visitors to the login form and answers 403 otherwise.
 */
final class NoCurrentShopException extends AccessDeniedException
{
    public function __construct(string $message = 'No shop is attached to the current producer.')
    {
        parent::__construct($message);
    }
}
