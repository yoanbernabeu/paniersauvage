<?php

declare(strict_types=1);

namespace App\Sale\Form;

use App\Sale\Form\Model\SaleOfferData;
use App\Shared\Form\QuantityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<SaleOfferData>
 */
final class SaleOfferRowType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('selected', CheckboxType::class, ['label' => 'Proposer', 'required' => false])
            ->add('priceCents', MoneyType::class, [
                'label' => 'Prix',
                'currency' => 'EUR',
                'divisor' => 100,
                'input' => 'integer',
                'required' => false,
                'invalid_message' => 'Ce prix n\'est pas valide.',
                'attr' => ['inputmode' => 'decimal'],
            ])
            ->add('maxQty', QuantityType::class, [
                'required' => false,
                'invalid_message' => 'Cette quantité n\'est pas valide.',
                'attr' => ['inputmode' => 'decimal', 'placeholder' => 'Illimitée'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SaleOfferData::class, 'label' => false]);
    }
}
