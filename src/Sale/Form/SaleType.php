<?php

declare(strict_types=1);

namespace App\Sale\Form;

use App\Sale\Form\Model\SaleData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Sale form. Delivery and offer rows come from the data (one per delivery point / product of
 * the shop): nothing is added or removed client-side, rows are ticked.
 *
 * @extends AbstractType<SaleData>
 */
final class SaleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Titre',
                'empty_data' => '',
                'attr' => ['placeholder' => 'Ex. Fournée du lundi', 'maxlength' => 160],
            ])
            ->add('deliveryDate', DateType::class, [
                'label' => 'Date de livraison',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'disabled' => $options['dates_locked'] || $options['delivery_date_locked'],
                'help' => $options['delivery_date_locked'] && !$options['dates_locked']
                    ? 'Des clients ont déjà commandé : la date de livraison ne change plus. Annule la vente si elle ne peut pas avoir lieu.'
                    : null,
            ])
            ->add('opensAt', DateTimeType::class, [
                'label' => 'Ouverture des commandes',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'disabled' => $options['dates_locked'] || $options['opening_locked'],
            ])
            ->add('deadlineAt', DateTimeType::class, [
                'label' => 'Date limite de commande',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'disabled' => $options['dates_locked'],
            ])
            ->add('allowCustomerEdit', CheckboxType::class, [
                'label' => 'Tes clients peuvent modifier ou annuler leur commande',
                'required' => false,
            ])
            ->add('editCutoffHours', IntegerType::class, [
                'label' => 'Modification possible jusqu\'à (heures avant la date limite)',
                'required' => false,
                'help' => 'Laisse vide pour autoriser les modifications jusqu\'à la date limite.',
                'attr' => ['min' => 0, 'inputmode' => 'numeric'],
            ])
            ->add('deliveries', CollectionType::class, [
                'entry_type' => SaleDeliveryRowType::class,
                'label' => false,
                'error_bubbling' => false,
            ])
            ->add('offers', CollectionType::class, [
                'entry_type' => SaleOfferRowType::class,
                'label' => false,
                'error_bubbling' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SaleData::class,
            // Closed sale: its dates no longer change.
            'dates_locked' => false,
            // Sale already open: its opening date no longer changes.
            'opening_locked' => false,
            // Sale with orders: its delivery date no longer changes.
            'delivery_date_locked' => false,
        ]);
        $resolver->setAllowedTypes('delivery_date_locked', 'bool');
        $resolver->setAllowedTypes('dates_locked', 'bool');
        $resolver->setAllowedTypes('opening_locked', 'bool');
    }
}
