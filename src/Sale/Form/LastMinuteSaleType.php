<?php

declare(strict_types=1);

namespace App\Sale\Form;

use App\Sale\Entity\SaleOffer;
use App\Sale\Form\Model\LastMinuteSaleData;
use App\Shared\Form\QuantityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\LessThanOrEqual;

/**
 * The end of the last-minute sale and, for each offer of the sale, the quantity put back on sale.
 *
 * @extends AbstractType<LastMinuteSaleData>
 */
final class LastMinuteSaleType extends AbstractType
{
    /** Largest quantity put back on sale for one product, in thousandths (9 999 units). */
    public const int MAX_QUANTITY = 9_999_000;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('until', DateTimeType::class, [
            'label' => 'Tes clients commandent jusqu\'au',
            'widget' => 'single_text',
            'input' => 'datetime_immutable',
        ]);

        $quantities = $builder->create('quantities', FormType::class);
        $offers = array_filter(\is_array($options['offers']) ? $options['offers'] : [], static fn (mixed $offer): bool => $offer instanceof SaleOffer);
        foreach ($offers as $offer) {
            $weighed = $offer->getProduct()->getUnit()->isWeighed();
            $quantities->add((string) $offer->getId(), QuantityType::class, [
                'required' => false,
                'decimals' => $weighed,
                'attr' => ['inputmode' => $weighed ? 'decimal' : 'numeric'],
                'constraints' => [new LessThanOrEqual(value: self::MAX_QUANTITY, message: 'C\'est beaucoup : indique au plus 9 999.')],
            ]);
        }
        $builder->add($quantities);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => LastMinuteSaleData::class]);
        $resolver->setRequired('offers');
        $resolver->setAllowedTypes('offers', 'array');
    }
}
