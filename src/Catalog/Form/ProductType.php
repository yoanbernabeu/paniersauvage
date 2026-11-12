<?php

declare(strict_types=1);

namespace App\Catalog\Form;

use App\Catalog\Entity\Product;
use App\Catalog\Enum\ProductKind;
use App\Catalog\Enum\ProductUnit;
use App\Shared\Form\QuantityType;
use App\Shop\Service\ImageStorage;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<Product>
 */
final class ProductType extends AbstractType
{
    /** VAT rates offered to producers, in basis points. */
    public const array VAT_RATES = [
        '0 %' => 0,
        '2,1 %' => 210,
        '5,5 %' => 550,
        '10 %' => 1000,
        '20 %' => 2000,
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nom du produit',
                'empty_data' => '',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('photo', FileType::class, [
                'label' => 'Photo',
                'mapped' => false,
                'required' => false,
                'help' => 'JPEG, PNG ou WebP, 5 Mo maximum.',
                'constraints' => [ImageStorage::constraint()],
                'attr' => ['accept' => implode(',', array_keys(ImageStorage::EXTENSIONS))],
            ])
            ->add('priceCents', MoneyType::class, [
                'label' => 'Prix TTC (€)',
                'currency' => false,
                'divisor' => 100,
                'input' => 'integer',
                'invalid_message' => 'Indique un prix en euros, par exemple 5,20.',
                'attr' => ['inputmode' => 'decimal', 'placeholder' => '5,20'],
            ])
            ->add('vatRateBp', ChoiceType::class, [
                'label' => 'TVA',
                'choices' => self::VAT_RATES,
                'help' => 'En franchise en base de TVA (réglage de ta boutique), ce taux ne s\'applique pas.',
            ])
            ->add('unit', EnumType::class, [
                'label' => 'Vendu à',
                'class' => ProductUnit::class,
                'choice_label' => static fn (ProductUnit $unit): string => $unit->label(),
            ])
            ->add('kind', EnumType::class, [
                'label' => 'Gamme',
                'class' => ProductKind::class,
                'choice_label' => static fn (ProductKind $kind): string => match ($kind) {
                    ProductKind::Permanent => 'Gamme permanente : proposé dans chaque vente',
                    ProductKind::Seasonal => 'Produit du moment : proposé pendant sa période',
                },
                'expanded' => true,
            ])
            ->add('availableFrom', DateType::class, [
                'label' => 'Disponible à partir du',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('availableUntil', DateType::class, [
                'label' => 'Jusqu\'au',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('maxQtyDefault', QuantityType::class, [
                'label' => 'Quantité maximale par vente',
                'required' => false,
                'help' => 'En pièces, bottes ou kilos selon le produit. Laisse vide si tu n\'as pas de limite. Tu pourras l\'ajuster à chaque vente.',
                'attr' => ['inputmode' => 'decimal'],
            ])
            ->add('quantityStep', ChoiceType::class, [
                'label' => 'Pas de vente',
                'choices' => Product::WEIGHT_STEPS,
                'help' => 'Produits au kilo : tes clients commandent par multiples de ce poids.',
            ])
            ->add('minQuantity', QuantityType::class, [
                'label' => 'Minimum par commande (kg)',
                'empty_data' => '1',
                'help' => 'Produits au kilo : par exemple 0,5 pour un demi-kilo au moins.',
                'attr' => ['inputmode' => 'decimal'],
            ])
            ->add('allergens', TextType::class, [
                'label' => 'Allergènes',
                'required' => false,
                'attr' => ['placeholder' => 'gluten, fruits à coque'],
            ])
            ->add('labels', TextType::class, [
                'label' => 'Labels',
                'required' => false,
                'help' => 'Sépare-les par des virgules.',
                'attr' => ['placeholder' => 'Bio, Levain naturel'],
            ])
            ->add('perishable', CheckboxType::class, [
                'label' => 'Denrée périssable (exclue du droit de rétractation)',
                'required' => false,
                'help' => 'Décoche pour un produit qui se conserve (vin, miel, huile, conserves) : tes clients pourront se rétracter pendant 14 jours après le retrait.',
            ])
            ->add('alcoholic', CheckboxType::class, [
                'label' => 'Boisson alcoolisée',
                'required' => false,
                'help' => 'Tes clients certifient avoir 18 ans en commandant, le message sanitaire s\'affiche, et ta feuille de livraison te rappelle de vérifier l\'âge au retrait.',
            ])
        ;

        if (true === $options['has_photo']) {
            $builder->add('removePhoto', CheckboxType::class, [
                'label' => 'Retirer la photo actuelle',
                'mapped' => false,
                'required' => false,
            ]);
        }

        $builder->get('labels')->addModelTransformer(new CallbackTransformer(
            static fn (mixed $labels): string => \is_array($labels) ? implode(', ', array_filter($labels, \is_string(...))) : '',
            static fn (mixed $text): array => \is_string($text) ? explode(',', $text) : [],
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Product::class, 'has_photo' => false]);
        $resolver->setAllowedTypes('has_photo', 'bool');
    }
}
