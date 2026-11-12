<?php

declare(strict_types=1);

namespace App\Sale\Form;

use App\Sale\Form\Model\SaleDeliveryData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<SaleDeliveryData>
 */
final class SaleDeliveryRowType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('selected', CheckboxType::class, ['label' => 'Livrer ici', 'required' => false]);

        // The time fields depend on the row: locked once customers have ordered on this delivery.
        $builder->addEventListener(FormEvents::PRE_SET_DATA, static function (FormEvent $event): void {
            $row = $event->getData();
            $locked = $row instanceof SaleDeliveryData && $row->timesLocked;
            $form = $event->getForm();

            $form
                ->add('startTime', TimeType::class, [
                    'label' => 'De',
                    'widget' => 'single_text',
                    'input' => 'datetime_immutable',
                    'required' => false,
                    'disabled' => $locked,
                ])
                ->add('endTime', TimeType::class, [
                    'label' => 'À',
                    'widget' => 'single_text',
                    'input' => 'datetime_immutable',
                    'required' => false,
                    'disabled' => $locked,
                ]);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SaleDeliveryData::class, 'label' => false]);
    }
}
