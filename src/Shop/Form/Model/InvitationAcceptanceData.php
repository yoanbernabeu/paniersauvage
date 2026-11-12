<?php

declare(strict_types=1);

namespace App\Shop\Form\Model;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * What an invited person types to create their producer account (same rules as the sign-up form).
 */
final class InvitationAcceptanceData
{
    #[Assert\NotBlank(message: 'Indique ton prénom.')]
    #[Assert\Length(max: 80)]
    public string $firstName = '';

    #[Assert\NotBlank(message: 'Indique ton nom.')]
    #[Assert\Length(max: 80)]
    public string $lastName = '';

    #[Assert\NotBlank(message: 'Choisis un mot de passe.')]
    #[Assert\Length(min: 8, max: 4096, minMessage: 'Ton mot de passe doit faire au moins {{ limit }} caractères.')]
    public string $plainPassword = '';

    /** Terms of use (App\Legal\LegalDocuments::TERMS_VERSION); checked by the form. */
    public bool $acceptTerms = false;
}
