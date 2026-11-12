<?php

declare(strict_types=1);

namespace App\Shop\Form\Model;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * What a producer types on the sign-up form.
 */
final class RegistrationData
{
    #[Assert\NotBlank(message: 'Indique ton prénom.')]
    #[Assert\Length(max: 80)]
    public string $firstName = '';

    #[Assert\NotBlank(message: 'Indique ton nom.')]
    #[Assert\Length(max: 80)]
    public string $lastName = '';

    #[Assert\NotBlank(message: 'Indique ton adresse e-mail.')]
    #[Assert\Email(message: 'Cette adresse e-mail ne semble pas valide.')]
    #[Assert\Length(max: 180)]
    public string $email = '';

    #[Assert\NotBlank(message: 'Choisis un mot de passe.')]
    #[Assert\Length(min: 8, max: 4096, minMessage: 'Ton mot de passe doit faire au moins {{ limit }} caractères.')]
    public string $plainPassword = '';

    #[Assert\NotBlank(message: 'Indique le nom de ton activité.')]
    #[Assert\Length(min: 2, max: 120, minMessage: 'Ce nom est trop court.')]
    public string $shopName = '';

    /** Terms of use and data processing agreement (App\Legal\LegalDocuments::TERMS_VERSION); checked by the form. */
    public bool $acceptTerms = false;
}
