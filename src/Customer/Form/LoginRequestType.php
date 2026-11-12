<?php

declare(strict_types=1);

namespace App\Customer\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The only thing a customer types to sign in: their e-mail address.
 *
 * @extends AbstractType<array{email?: string}>
 */
final class LoginRequestType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('email', EmailType::class, [
            'label' => 'Ton adresse e-mail',
            'attr' => ['autocomplete' => 'email', 'inputmode' => 'email', 'placeholder' => 'camille@exemple.fr', 'autofocus' => true],
            'constraints' => [
                new Assert\NotBlank(message: 'Indique ton adresse e-mail.'),
                new Assert\Email(message: 'Cette adresse e-mail ne semble pas valide.'),
                new Assert\Length(max: 180),
            ],
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'login';
    }
}
