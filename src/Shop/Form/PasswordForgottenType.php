<?php

declare(strict_types=1);

namespace App\Shop\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @extends AbstractType<array{email: string}>
 */
final class PasswordForgottenType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('email', EmailType::class, [
            'label' => 'Adresse e-mail',
            'empty_data' => '',
            'attr' => ['autocomplete' => 'email', 'inputmode' => 'email', 'autofocus' => true],
            'constraints' => [
                new Assert\NotBlank(message: 'Indique ton adresse e-mail.'),
                new Assert\Email(message: 'Cette adresse e-mail ne semble pas valide.'),
                new Assert\Length(max: 180),
            ],
        ]);
    }
}
