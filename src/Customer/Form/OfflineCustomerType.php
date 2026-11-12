<?php

declare(strict_types=1);

namespace App\Customer\Form;

use App\Customer\Form\Model\OfflineCustomerData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A producer corrects a customer without e-mail, or gives them an address.
 *
 * @extends AbstractType<OfflineCustomerData>
 */
final class OfflineCustomerType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstName', TextType::class, ['label' => 'Prénom', 'attr' => ['autocomplete' => 'off']])
            ->add('lastName', TextType::class, ['label' => 'Nom', 'attr' => ['autocomplete' => 'off']])
            ->add('phone', TelType::class, ['label' => 'Téléphone', 'attr' => ['autocomplete' => 'off', 'inputmode' => 'tel']])
            ->add('email', EmailType::class, [
                'label' => 'E-mail',
                'required' => false,
                'help' => 'Avec une adresse, ce client devient un compte comme les autres : il pourra se connecter et recevra les prochains e-mails. Si un compte utilise déjà cette adresse, ses commandes y sont rattachées.',
                'attr' => ['autocomplete' => 'off', 'inputmode' => 'email'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => OfflineCustomerData::class]);
    }

    public function getBlockPrefix(): string
    {
        return 'offline_customer';
    }
}
