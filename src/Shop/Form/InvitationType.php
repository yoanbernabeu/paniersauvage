<?php

declare(strict_types=1);

namespace App\Shop\Form;

use App\Shop\Form\Model\InvitationData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<InvitationData>
 */
final class InvitationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('email', EmailType::class, [
            'label' => 'Adresse e-mail',
            'empty_data' => '',
            'help' => 'La personne reçoit un lien, valable 7 jours, pour créer son mot de passe.',
            'attr' => ['autocomplete' => 'off', 'inputmode' => 'email'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => InvitationData::class]);
    }
}
