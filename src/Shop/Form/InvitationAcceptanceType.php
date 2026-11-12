<?php

declare(strict_types=1);

namespace App\Shop\Form;

use App\Legal\Form\TermsAcceptanceType;
use App\Shop\Form\Model\InvitationAcceptanceData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * @extends AbstractType<InvitationAcceptanceData>
 */
final class InvitationAcceptanceType extends AbstractType
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstName', TextType::class, [
                'label' => 'Prénom',
                'empty_data' => '',
                'attr' => ['autocomplete' => 'given-name', 'autofocus' => true],
            ])
            ->add('lastName', TextType::class, [
                'label' => 'Nom',
                'empty_data' => '',
                'attr' => ['autocomplete' => 'family-name'],
            ])
            ->add('plainPassword', PasswordType::class, [
                'label' => 'Mot de passe',
                'empty_data' => '',
                'help' => '8 caractères minimum.',
                'attr' => ['autocomplete' => 'new-password'],
            ])
            ->add('acceptTerms', CheckboxType::class, TermsAcceptanceType::checkboxOptions($this->urlGenerator, 'J\'accepte les'))
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => InvitationAcceptanceData::class]);
    }
}
