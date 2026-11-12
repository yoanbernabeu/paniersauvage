<?php

declare(strict_types=1);

namespace App\Shop\Form\Model;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The address the owner of a shop invites to join the team.
 */
final class InvitationData
{
    #[Assert\NotBlank(message: 'Indique l\'adresse e-mail de la personne à inviter.')]
    #[Assert\Email(message: 'Cette adresse e-mail ne semble pas valide.')]
    #[Assert\Length(max: 180)]
    public string $email = '';
}
