<?php

declare(strict_types=1);

namespace App\Sale\Form;

use App\Catalog\Entity\Product;
use App\Delivery\Entity\DeliveryPoint;
use App\Sale\Entity\SaleSchedule;
use App\Shared\Service\FrenchFormatter;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Count;

/**
 * @extends AbstractType<SaleSchedule>
 */
final class SaleScheduleType extends AbstractType
{
    public function __construct(
        private readonly FrenchFormatter $formatter,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $weekdays = [];
        foreach (range(1, 7) as $weekday) {
            $weekdays[ucfirst($this->formatter->weekday($weekday))] = $weekday;
        }

        $time = ['widget' => 'single_text', 'input' => 'datetime_immutable'];

        $builder
            ->add('name', TextType::class, [
                'label' => 'Nom',
                'empty_data' => '',
                'help' => 'Il sert de titre aux ventes créées.',
                'attr' => ['placeholder' => 'Ex. Livraison du lundi', 'maxlength' => 120],
            ])
            ->add('weekday', ChoiceType::class, ['label' => 'Jour de livraison', 'choices' => $weekdays])
            ->add('deliveryStartTime', TimeType::class, $time + ['label' => 'Début du créneau'])
            ->add('deliveryEndTime', TimeType::class, $time + ['label' => 'Fin du créneau'])
            ->add('deadlineDaysBefore', IntegerType::class, [
                'label' => 'Date limite : jours avant la livraison',
                'help' => '3 pour un vendredi quand tu livres le lundi.',
                'attr' => ['min' => 0, 'max' => 30, 'inputmode' => 'numeric'],
            ])
            ->add('deadlineTime', TimeType::class, $time + ['label' => 'Heure limite'])
            ->add('opensDaysBefore', IntegerType::class, [
                'label' => 'Ouverture : jours avant la livraison',
                'help' => 'La vente ouvre ce jour-là à 8 h.',
                'attr' => ['min' => 1, 'max' => 60, 'inputmode' => 'numeric'],
            ])
            ->add('editCutoffHours', IntegerType::class, [
                'label' => 'Modification client : heures avant la date limite',
                'required' => false,
                'help' => 'Laisse vide pour autoriser les modifications jusqu\'à la date limite.',
                'attr' => ['min' => 0, 'inputmode' => 'numeric'],
            ])
            ->add('deliveryPoints', EntityType::class, [
                'label' => 'Points de livraison',
                'class' => DeliveryPoint::class,
                'choices' => $options['delivery_points'],
                'choice_label' => static fn (DeliveryPoint $point): string => $point->getName(),
                'multiple' => true,
                'expanded' => true,
                'by_reference' => false,
                'constraints' => [new Count(min: 1, minMessage: 'Choisis au moins un point de livraison.')],
            ])
            ->add('products', EntityType::class, [
                'label' => 'Produits proposés',
                'class' => Product::class,
                'choices' => $options['products'],
                'choice_label' => static fn (Product $product): string => $product->isSeasonal() ? $product->getName().' (du moment)' : $product->getName(),
                'multiple' => true,
                'expanded' => true,
                'by_reference' => false,
                'required' => false,
                'help' => 'Un produit du moment n\'est proposé que pendant sa période. Si tu ne coches rien, toute ta gamme permanente et tes produits du moment en saison sont proposés.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SaleSchedule::class]);
        $resolver->setRequired(['delivery_points', 'products']);
        $resolver->setAllowedTypes('delivery_points', DeliveryPoint::class.'[]');
        $resolver->setAllowedTypes('products', Product::class.'[]');
    }
}
