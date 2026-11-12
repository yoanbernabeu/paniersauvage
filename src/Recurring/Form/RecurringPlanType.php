<?php

declare(strict_types=1);

namespace App\Recurring\Form;

use App\Catalog\Entity\Product;
use App\Recurring\Entity\RecurringPlan;
use App\Recurring\Enum\RecurringMode;
use App\Recurring\Enum\RecurringTerm;
use App\Shared\Form\QuantityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Producer settings of the recurring orders of a recurrence: one quantity per product of the
 * recurrence (0 = not in the plan; quantity of one basket, or most per subscriber), places,
 * contract and conditions.
 *
 * Option `products`: the products the plan may hold (those of the recurrence, else the range).
 *
 * @extends AbstractType<RecurringPlan>
 */
final class RecurringPlanType extends AbstractType
{
    /** Most of one product in a plan: 99 pieces, bunches or kilograms. */
    public const int MAX_QUANTITY = 99_000;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<Product> $products */
        $products = $options['products'];

        $builder
            ->add('enabled', CheckboxType::class, [
                'label' => 'Ouvrir les inscriptions',
                'required' => false,
                'help' => 'Le panier apparaît sur ta page et sur celle des ventes. Fermer les inscriptions n\'arrête pas les abonnements en cours.',
            ])
            ->add('title', TextType::class, ['label' => 'Nom du panier', 'attr' => ['placeholder' => 'Panier de légumes de la semaine']])
            ->add('description', TextareaType::class, ['label' => 'Description', 'required' => false, 'attr' => ['rows' => 2]])
            ->add('mode', EnumType::class, [
                'class' => RecurringMode::class,
                'label' => 'Composition',
                'expanded' => true,
                'choice_label' => static fn (RecurringMode $mode): string => $mode->label(),
            ])
            ->add('maxShares', IntegerType::class, [
                'label' => 'Paniers par abonné, au plus',
                'help' => 'Panier composé : un abonné peut prendre plusieurs paniers à chaque livraison.',
                'attr' => ['min' => 1, 'max' => RecurringPlan::MAX_SHARES, 'inputmode' => 'numeric'],
            ])
            ->add('capacity', IntegerType::class, [
                'label' => 'Nombre de places',
                'required' => false,
                'help' => 'Vide : sans limite. Les abonnements suspendus gardent leur place.',
                'attr' => ['min' => 1, 'inputmode' => 'numeric'],
            ])
            ->add('term', EnumType::class, [
                'class' => RecurringTerm::class,
                'label' => 'Engagement',
                'expanded' => true,
                'choice_label' => static fn (RecurringTerm $term): string => $term->label(),
            ])
            ->add('seasonStartsOn', DateType::class, ['label' => 'Première livraison du contrat', 'required' => false, 'widget' => 'single_text', 'input' => 'datetime_immutable'])
            ->add('seasonEndsOn', DateType::class, ['label' => 'Dernière livraison du contrat', 'required' => false, 'widget' => 'single_text', 'input' => 'datetime_immutable'])
            ->add('noticeDays', IntegerType::class, [
                'label' => 'Préavis de résiliation (jours)',
                'help' => 'Sans engagement : les livraisons de ces jours restent dues après une résiliation. 0 : résiliation immédiate.',
                'attr' => ['min' => 0, 'max' => 60, 'inputmode' => 'numeric'],
            ])
            ->add('maxPauses', IntegerType::class, [
                'label' => 'Absences possibles par abonné',
                'required' => false,
                'help' => 'Nombre de livraisons qu\'un abonné peut mettre en pause. Vide : autant qu\'il veut.',
                'attr' => ['min' => 0, 'inputmode' => 'numeric'],
            ])
            ->add('conditions', TextareaType::class, [
                'label' => 'Conditions affichées aux abonnés',
                'required' => false,
                'help' => 'Ce que tu attends d\'eux : panier oublié, prévenir d\'une absence, paiement à chaque retrait…',
                'attr' => ['rows' => 4],
            ]);

        $plan = $options['data'] ?? null;
        $quantities = $builder->create('quantities', FormType::class, ['label' => false, 'mapped' => false]);
        foreach ($products as $product) {
            $weighed = $product->getUnit()->isWeighed();
            $quantities->add((string) $product->getId(), QuantityType::class, [
                'label' => $product->getName(),
                'data' => $plan instanceof RecurringPlan ? $plan->getItemFor($product)?->getQuantity() : null,
                'required' => false,
                'empty_data' => '0',
                'decimals' => $weighed,
                'attr' => ['inputmode' => $weighed ? 'decimal' : 'numeric', 'class' => 'max-w-32'],
                'invalid_message' => $weighed ? 'Indique un poids en kilos, par exemple 1,5.' : 'Indique un nombre entier.',
                'constraints' => [new Callback(static function (mixed $quantity, ExecutionContextInterface $context): void {
                    if (\is_int($quantity) && $quantity > self::MAX_QUANTITY) {
                        $context->buildViolation('99 au plus.')->addViolation();
                    }
                })],
            ]);
        }
        $builder->add($quantities);

        // Before validation (priority > 0): the plan holds the typed quantities, so that its own
        // constraints (at least one product) see them.
        $builder->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event) use ($products): void {
            $plan = $event->getData();
            if (!$plan instanceof RecurringPlan) {
                return;
            }

            $fields = $event->getForm()->get('quantities');
            foreach ($products as $position => $product) {
                $quantity = $fields->get((string) $product->getId())->getData();
                // QuantityType already refuses negative numbers and fractions of a piece (null then).
                if (!\is_int($quantity) || $quantity > self::MAX_QUANTITY) {
                    continue;
                }
                $plan->setItemQuantity($product, $quantity, $position);
            }
        }, 10);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => RecurringPlan::class]);
        $resolver->setRequired('products');
        $resolver->setAllowedTypes('products', 'array');
    }
}
