<?php

declare(strict_types=1);

namespace App\Shop\Form\Model;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The new password typed after following a reset link (same rules as on the sign-up form).
 */
final class NewPasswordData
{
    #[Assert\NotBlank(message: 'Choisis un mot de passe.')]
    #[Assert\Length(min: 8, max: 4096, minMessage: 'Ton mot de passe doit faire au moins {{ limit }} caractères.')]
    public string $plainPassword = '';
}
