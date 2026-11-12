<?php

declare(strict_types=1);

namespace App\Customer\Form;

use App\Customer\Entity\Customer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<Customer>
 */
final class ProfileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstName', TextType::class, [
                'label' => 'Prénom',
                'required' => false,
                'attr' => ['autocomplete' => 'given-name'],
            ])
            ->add('lastName', TextType::class, [
                'label' => 'Nom',
                'required' => false,
                'attr' => ['autocomplete' => 'family-name'],
            ])
            ->add('phone', TelType::class, [
                'label' => 'Téléphone',
                'required' => false,
                'help' => 'Pratique pour que le producteur te joigne le jour de la livraison.',
                'attr' => ['autocomplete' => 'tel', 'inputmode' => 'tel'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Customer::class]);
    }

    public function getBlockPrefix(): string
    {
        return 'profile';
    }
}
