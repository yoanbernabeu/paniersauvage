<?php

declare(strict_types=1);

namespace App\Shared\Form;

use App\Shared\Service\Quantity;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A quantity typed as a decimal number ("1,5" or "1.5") and stored in thousandths of the unit
 * (App\Shared\Service\Quantity): "1,5" ↔ 1500. With `decimals: false`, only whole numbers are
 * accepted ("2" ↔ 2000). Parsed as a string, never through a float.
 *
 * @extends AbstractType<int|null>
 */
final class QuantityType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $decimals = true === $options['decimals'];

        $builder->addModelTransformer(new CallbackTransformer(
            static fn (mixed $quantity): string => \is_int($quantity) ? Quantity::decimal($quantity) : '',
            static function (mixed $value) use ($decimals): ?int {
                // TextType trims what was typed: blank is ''.
                if (!\is_string($value) || '' === $value) {
                    return null;
                }

                $quantity = Quantity::parse($value);
                if (null === $quantity || (!$decimals && !Quantity::isWhole($quantity))) {
                    throw new TransformationFailedException('Not a quantity.');
                }

                return $quantity;
            },
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'decimals' => true,
            'invalid_message' => static fn (Options $options): string => true === $options['decimals']
                ? 'Indique une quantité, par exemple 1,5.'
                : 'Indique un nombre entier.',
        ]);
        $resolver->setAllowedTypes('decimals', 'bool');
    }

    public function getParent(): string
    {
        return TextType::class;
    }
}
