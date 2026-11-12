<?php

declare(strict_types=1);

namespace App\Billing\Form;

use App\Billing\Enum\SolidarityCriterion;
use App\Billing\Service\SolidarityPlan;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Declaration on honour of the solidarity plan: situations ticked, amount chosen, honour box.
 *
 * @extends AbstractType<SolidarityDeclaration>
 */
final class SolidarityDeclarationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('criteria', EnumType::class, [
                'label' => 'Ta situation (une seule suffit)',
                'class' => SolidarityCriterion::class,
                'choice_label' => static fn (SolidarityCriterion $criterion): string => $criterion->label(),
                'choice_attr' => static fn (SolidarityCriterion $criterion): array => ['data-criterion' => $criterion->value],
                'multiple' => true,
                'expanded' => true,
                'help' => 'Aucun justificatif à fournir : ta parole suffit.',
            ])
            ->add('amountEuros', IntegerType::class, [
                'label' => 'Ton prix, en euros par mois',
                'help' => \sprintf('De %d à %d €, %d € suggérés. À 0 €, rien à payer ni carte à donner : ton palier dure %d mois, renouvelable.', SolidarityPlan::MIN_EUROS, SolidarityPlan::MAX_EUROS, SolidarityPlan::SUGGESTED_EUROS, SolidarityPlan::FREE_PERIOD_MONTHS),
                'attr' => ['min' => SolidarityPlan::MIN_EUROS, 'max' => SolidarityPlan::MAX_EUROS, 'step' => 1, 'inputmode' => 'numeric'],
                'invalid_message' => 'Indique un nombre entier d\'euros, entre 0 et 10.',
            ])
            ->add('honour', CheckboxType::class, [
                'label' => 'Je déclare sur l\'honneur que ma situation correspond à ce que j\'ai coché.',
                'required' => true,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SolidarityDeclaration::class,
            'csrf_token_id' => 'billing_solidarity',
        ]);
    }
}
