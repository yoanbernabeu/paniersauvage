<?php

declare(strict_types=1);

namespace App\Shop\Form;

use App\Legal\Form\TermsAcceptanceType;
use App\Shop\Form\Model\RegistrationData;
use App\Shop\Repository\UserRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * @extends AbstractType<RegistrationData>
 */
final class RegistrationType extends AbstractType
{
    public function __construct(
        private readonly UserRepository $users,
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
            ->add('shopName', TextType::class, [
                'label' => 'Nom de ton activité',
                'empty_data' => '',
                'help' => 'Celui que tes clients connaissent, par exemple « Le Fournil du Pont ».',
                'attr' => ['autocomplete' => 'organization'],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Adresse e-mail',
                'empty_data' => '',
                'attr' => ['autocomplete' => 'email', 'inputmode' => 'email'],
            ])
            ->add('plainPassword', PasswordType::class, [
                'label' => 'Mot de passe',
                'empty_data' => '',
                'help' => '8 caractères minimum.',
                'attr' => ['autocomplete' => 'new-password'],
            ])
            ->add('acceptTerms', CheckboxType::class, TermsAcceptanceType::checkboxOptions($this->urlGenerator, 'J\'accepte les'))
        ;

        $builder->get('email')->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $email = $event->getData();

            if (\is_string($email) && '' !== $email && null !== $this->users->findOneBy(['email' => mb_strtolower(trim($email))])) {
                $event->getForm()->addError(new FormError('Un compte existe déjà avec cette adresse e-mail.'));
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => RegistrationData::class]);
    }
}
