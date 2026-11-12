<?php

declare(strict_types=1);

namespace App\Recurring\Form;

use App\Delivery\Entity\DeliveryPoint;
use App\Recurring\Entity\RecurringPlan;
use App\Shared\Form\QuantityType;
use App\Shared\Service\Quantity;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\IsTrue;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Subscription form: pick-up point, baskets (basket mode) or quantities (choice mode, up to what
 * the producer proposes, within the selling step of products sold by weight).
 *
 * Options: `plan` (required), `subscribe` (first subscription: mandatory box "I read the
 * conditions", and "I am 18 or over" when the plan offers an alcoholic drink), `terms` (mandatory
 * "I accept the terms", when the customer has not accepted the current version).
 *
 * @extends AbstractType<RecurringOrderFormData>
 */
final class RecurringOrderType extends AbstractType
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $plan = $options['plan'];
        if (!$plan instanceof RecurringPlan) {
            throw new \InvalidArgumentException('The "plan" option must be a RecurringPlan.');
        }

        $builder
            ->add('deliveryPoint', ChoiceType::class, [
                'label' => 'Point de retrait',
                'choices' => RecurringOrderFormData::pointsOf($plan),
                'choice_value' => static fn (?DeliveryPoint $point): string => (string) $point?->getId(),
                'choice_label' => static fn (DeliveryPoint $point): string => $point->getName(),
                'expanded' => true,
                'placeholder' => false,
                'invalid_message' => 'Ce point de retrait n\'est pas proposé pour ce panier.',
                'constraints' => [new NotNull(message: 'Choisis où récupérer tes paniers.')],
            ])
            ->add('deliveryAddress', TextareaType::class, [
                'label' => 'Adresse de livraison',
                'required' => false,
                'attr' => ['rows' => 2, 'autocomplete' => 'street-address', 'placeholder' => 'Numéro, rue, code postal, commune'],
                'constraints' => [new Length(max: 500, maxMessage: 'Cette adresse est trop longue ({{ limit }} caractères maximum).')],
            ]);

        if ($plan->isBasket()) {
            $builder->add('shares', IntegerType::class, [
                'label' => 'Nombre de paniers à chaque livraison',
                'attr' => ['min' => 1, 'max' => $plan->getMaxShares(), 'inputmode' => 'numeric'],
                'constraints' => [new Range(notInRangeMessage: 'Choisis entre {{ min }} et {{ max }} paniers.', min: 1, max: $plan->getMaxShares())],
            ]);
        } else {
            $quantities = $builder->create('quantities', FormType::class, ['label' => false]);
            foreach ($plan->getItems() as $item) {
                $product = $item->getProduct();
                $weighed = $product->getUnit()->isWeighed();
                $max = $item->getQuantity();
                $quantities->add((string) $product->getId(), QuantityType::class, [
                    'label' => $product->getName(),
                    'required' => false,
                    'empty_data' => '0',
                    'decimals' => $weighed,
                    'help' => \sprintf('%s au plus%s', $weighed ? Quantity::decimal($max).' kg' : Quantity::decimal($max), $weighed ? \sprintf(', par %s kg', Quantity::decimal($product->getQuantityStep())) : ''),
                    'attr' => ['inputmode' => $weighed ? 'decimal' : 'numeric', 'class' => 'max-w-32'],
                    'invalid_message' => $weighed ? 'Indique un poids en kilos, par exemple 1,5.' : 'Indique un nombre entier.',
                    'constraints' => [new Callback(static function (mixed $quantity, ExecutionContextInterface $context) use ($product, $max, $weighed): void {
                        if (!\is_int($quantity) || 0 === $quantity) {
                            return;
                        }
                        if ($quantity > $max) {
                            $context->buildViolation(\sprintf('%s au plus.', $weighed ? Quantity::decimal($max).' kg' : Quantity::decimal($max)))->addViolation();
                        } elseif (!$product->acceptsOrderQuantity($quantity)) {
                            $context->buildViolation($weighed
                                ? \sprintf('Choisis un multiple de %s kg, %s kg au moins.', Quantity::decimal($product->getQuantityStep()), Quantity::decimal($product->getMinQuantity()))
                                : 'Indique un nombre entier.')->addViolation();
                        }
                    })],
                ]);
            }
            $builder->add($quantities);
        }

        if (true === $options['subscribe']) {
            $builder->add('acceptConditions', CheckboxType::class, [
                'label' => $plan->isSeason()
                    ? 'J\'ai lu les conditions et je m\'engage pour toute la durée du contrat.'
                    : 'J\'ai lu les conditions de cet abonnement.',
                'required' => true,
                'constraints' => [new IsTrue(message: 'Lis et accepte les conditions de l\'abonnement pour continuer.')],
            ]);

            if ($plan->containsAlcohol()) {
                $builder->add('adultCertified', CheckboxType::class, [
                    'label' => 'Je certifie avoir 18 ans ou plus',
                    'required' => true,
                    'constraints' => [new IsTrue(message: 'La vente d\'alcool est interdite aux mineurs : certifie avoir 18 ans ou plus pour t\'abonner.')],
                ]);
            }
        }

        if (true === $options['terms']) {
            $builder->add('acceptTerms', CheckboxType::class, [
                'label' => \sprintf(
                    'J\'accepte les <a href="%s#clients" class="link" target="_blank" rel="noopener">conditions d\'utilisation</a> et j\'ai lu la <a href="%s" class="link" target="_blank" rel="noopener">politique de confidentialité</a>.',
                    $this->urlGenerator->generate('legal_terms'),
                    $this->urlGenerator->generate('legal_privacy'),
                ),
                'label_html' => true,
                'required' => true,
                'constraints' => [new IsTrue(message: 'Accepte les conditions d\'utilisation pour t\'abonner.')],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RecurringOrderFormData::class,
            'subscribe' => false,
            'terms' => false,
            'constraints' => static fn (Options $options): array => [
                new Callback(static function (RecurringOrderFormData $data, ExecutionContextInterface $context) use ($options): void {
                    $plan = $options['plan'];
                    if ($plan instanceof RecurringPlan && !$plan->isBasket() && [] === array_filter($data->quantities)) {
                        $context->buildViolation('Choisis au moins un produit.')->addViolation();
                    }

                    if (true === $data->deliveryPoint?->isHome() && '' === trim($data->deliveryAddress ?? '')) {
                        $context->buildViolation('Indique ton adresse pour la livraison à domicile.')->atPath('deliveryAddress')->addViolation();
                    }
                }),
            ],
        ]);
        $resolver->setRequired('plan');
        $resolver->setAllowedTypes('plan', RecurringPlan::class);
        $resolver->setAllowedTypes('subscribe', 'bool');
        $resolver->setAllowedTypes('terms', 'bool');
    }
}
