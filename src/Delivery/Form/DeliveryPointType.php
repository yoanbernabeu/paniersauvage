<?php

declare(strict_types=1);

namespace App\Delivery\Form;

use App\Delivery\Entity\DeliveryPoint;
use App\Delivery\Enum\DeliveryPointType as PointType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<DeliveryPoint>
 */
final class DeliveryPointType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('type', EnumType::class, [
                'label' => 'Type',
                'class' => PointType::class,
                'choice_label' => static fn (PointType $type): string => match ($type) {
                    PointType::MeetingPoint => 'Point de rendez-vous : tes clients viennent chercher leur commande',
                    PointType::TourStop => 'Arrêt de tournée : tu passes à heure fixe',
                    PointType::Home => 'Livraison à domicile : le client indique son adresse',
                },
                'expanded' => true,
            ])
            ->add('name', TextType::class, [
                'label' => 'Nom',
                'empty_data' => '',
                'attr' => ['placeholder' => 'Place du village'],
            ])
            ->add('address', TextareaType::class, [
                'label' => 'Adresse',
                'required' => false,
                'help' => 'Inutile pour la livraison à domicile.',
                'attr' => ['rows' => 2],
            ])
            ->add('zoneTown', TextType::class, [
                'label' => 'Commune au centre de ta zone',
                'required' => false,
                'help' => 'Livraison à domicile seulement. Avec le rayon, les clients de ta zone te trouvent dans l\'annuaire.',
                'attr' => ['placeholder' => 'Crest', 'autocomplete' => 'off'],
            ])
            ->add('zoneRadiusKm', IntegerType::class, [
                'label' => 'Rayon de livraison (km)',
                'required' => false,
                'attr' => ['min' => 1, 'max' => DeliveryPoint::MAX_ZONE_RADIUS_KM, 'inputmode' => 'numeric', 'placeholder' => '10'],
            ])
            ->add('instructions', TextareaType::class, [
                'label' => 'Indications pour tes clients',
                'required' => false,
                'attr' => ['rows' => 2, 'placeholder' => 'Devant la mairie, camionnette blanche'],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => DeliveryPoint::class]);
    }
}
