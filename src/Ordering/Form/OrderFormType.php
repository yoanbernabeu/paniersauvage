<?php

declare(strict_types=1);

namespace App\Ordering\Form;

use App\Catalog\Entity\Product;
use App\Ordering\Entity\Order;
use App\Ordering\Service\OrderWeighing;
use App\Sale\Entity\Sale;
use App\Sale\Entity\SaleDelivery;
use App\Sale\Entity\SaleOffer;
use App\Shared\Form\QuantityType;
use App\Shared\Service\Quantity;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\IsTrue;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * The order form: delivery, one quantity per product of the sale, free note, and — when asked —
 * who the order is for.
 *
 * Options: `sale` (required), `identity` (first name, last name, phone), `email` (e-mail field,
 * for guests and for producers adding an order), `require_items` (refuse an empty basket),
 * `terms` (mandatory "I accept the terms" box), `age_check` ("I am 18 or over" box, mandatory
 * when the basket holds an alcoholic drink; only added when the sale offers one),
 * `email_optional` (producers only: an order without e-mail, for a customer who gives a phone
 * number, see App\Customer\Service\OfflineCustomerManager), `selling_steps` (products sold by weight: only multiples of their step, from their minimum;
 * producers may type any weight), `weights` (the order being edited by its producer: one
 * "actual weight" field per line sold by weight).
 *
 * Quantities are in thousandths of the unit (App\Shared\Service\Quantity), typed as decimals
 * ("1,5") for products sold by weight and as whole numbers otherwise.
 *
 * @extends AbstractType<OrderFormData>
 */
final class OrderFormType extends AbstractType
{
    /** Most of one product in an order: 99 pieces, bunches or kilograms. */
    public const int MAX_QUANTITY = 99;

    /** Hidden field only bots fill in (`honeypot` option): such a submission is dropped. */
    public const string HONEYPOT_FIELD = 'website';

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $sale = $options['sale'];
        if (!$sale instanceof Sale) {
            throw new \InvalidArgumentException('The "sale" option must be a Sale.');
        }

        $builder
            ->add('delivery', ChoiceType::class, [
                'label' => 'Point de livraison',
                'choices' => $sale->getDeliveries()->toArray(),
                'choice_value' => static fn (?SaleDelivery $delivery): string => (string) $delivery?->getId(),
                'choice_label' => static fn (SaleDelivery $delivery): string => $delivery->getDeliveryPoint()->getName(),
                'expanded' => true,
                'multiple' => false,
                'placeholder' => false,
                'invalid_message' => 'Ce point de livraison n\'est pas proposé pour cette vente.',
                'constraints' => [new NotNull(message: 'Choisis où récupérer ta commande.')],
            ])
            ->add('deliveryAddress', TextareaType::class, [
                'label' => 'Adresse de livraison',
                'required' => false,
                'attr' => ['rows' => 2, 'autocomplete' => 'street-address', 'placeholder' => 'Numéro, rue, code postal, commune'],
                'constraints' => [new Length(max: 500, maxMessage: 'Cette adresse est trop longue ({{ limit }} caractères maximum).')],
            ])
            ->add('note', TextareaType::class, [
                'label' => 'Un mot pour le producteur',
                'required' => false,
                'attr' => ['rows' => 2, 'placeholder' => 'Bien cuit, tranché, un voisin passera à ma place…'],
                'constraints' => [new Length(max: 1000, maxMessage: 'Ce message est trop long ({{ limit }} caractères maximum).')],
            ]);

        $quantities = $builder->create('quantities', FormType::class, ['label' => false]);
        $steps = true === $options['selling_steps'];
        $offers = array_filter(\is_array($options['offers']) ? $options['offers'] : $sale->getOffers()->toArray(), static fn (mixed $offer): bool => $offer instanceof SaleOffer);
        foreach ($offers as $offer) {
            $product = $offer->getProduct();
            $weighed = $product->getUnit()->isWeighed();
            // Rendered by hand (steppers): label and attributes live in the templates.
            $quantities->add((string) $offer->getId(), QuantityType::class, [
                'required' => false,
                'empty_data' => '0',
                'decimals' => $weighed,
                'invalid_message' => $weighed ? 'Indique un poids en kilos, par exemple 1,5.' : 'Indique un nombre entier.',
                'constraints' => [new Callback(static function (mixed $quantity, ExecutionContextInterface $context) use ($product, $steps): void {
                    if (\is_int($quantity) && 0 !== $quantity && null !== ($message = self::refusal($product, $quantity, $steps))) {
                        $context->buildViolation($message)->addViolation();
                    }
                })],
            ]);
        }
        $builder->add($quantities);

        $order = $options['weights'];
        if ($order instanceof Order) {
            $weights = $builder->create('actualQuantities', FormType::class);
            foreach ($order->getLines() as $line) {
                if ($line->isWeighed()) {
                    $weights->add((string) $line->getId(), QuantityType::class, [
                        'label' => $line->getLabel(),
                        'required' => false,
                        'invalid_message' => 'Indique un poids en kilos, par exemple 1,25.',
                        'attr' => ['inputmode' => 'decimal', 'placeholder' => Quantity::decimal($line->getQuantity())],
                        'constraints' => [new Callback(static function (mixed $weight, ExecutionContextInterface $context): void {
                            if (\is_int($weight) && ($weight <= 0 || $weight > OrderWeighing::MAX_WEIGHT)) {
                                $context->buildViolation(\sprintf('Indique un poids entre 0,001 et %d kg.', intdiv(OrderWeighing::MAX_WEIGHT, Quantity::UNIT)))->addViolation();
                            }
                        })],
                    ]);
                }
            }
            $builder->add($weights);
        }

        if (true === $options['email']) {
            $optional = true === $options['email_optional'];
            $builder->add('email', EmailType::class, [
                'label' => 'E-mail',
                'required' => !$optional,
                'attr' => ['autocomplete' => 'email', 'inputmode' => 'email'],
                'constraints' => [
                    ...($optional ? [] : [new NotBlank(message: 'Indique une adresse e-mail.')]),
                    new Email(message: 'Cette adresse e-mail ne semble pas valide.'),
                    new Length(max: 180),
                ],
            ]);
        }

        if (true === $options['honeypot']) {
            $builder->add(self::HONEYPOT_FIELD, TextType::class, [
                'label' => 'Ne remplis pas ce champ',
                'mapped' => false,
                'required' => false,
                'attr' => ['tabindex' => '-1', 'autocomplete' => 'off'],
            ]);
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
                'constraints' => [new IsTrue(message: 'Accepte les conditions d\'utilisation pour valider ta commande.')],
            ]);
        }

        if (true === $options['age_check'] && $this->offersAlcohol($sale)) {
            $builder->add('adultCertified', CheckboxType::class, [
                'label' => 'Je certifie avoir 18 ans ou plus',
                'required' => false,
            ]);
        }

        if (true === $options['identity']) {
            $builder
                ->add('firstName', TextType::class, [
                    'label' => 'Prénom',
                    'attr' => ['autocomplete' => 'given-name'],
                    'constraints' => [new NotBlank(message: 'Indique un prénom.'), new Length(max: 80)],
                ])
                ->add('lastName', TextType::class, [
                    'label' => 'Nom',
                    'attr' => ['autocomplete' => 'family-name'],
                    'constraints' => [new NotBlank(message: 'Indique un nom.'), new Length(max: 80)],
                ])
                ->add('phone', TelType::class, [
                    'label' => 'Téléphone',
                    'required' => false,
                    'attr' => ['autocomplete' => 'tel'],
                    'constraints' => [new Length(max: 30)],
                ]);
        }
    }

    private function offersAlcohol(Sale $sale): bool
    {
        return $sale->getOffers()->exists(static fn (int $key, SaleOffer $offer): bool => $offer->getProduct()->isAlcoholic());
    }

    /**
     * Why this quantity (thousandths) of the product cannot be ordered, or null when it can. With
     * `$steps`, the selling step and minimum of a product sold by weight apply too.
     */
    private static function refusal(Product $product, int $quantity, bool $steps): ?string
    {
        // Negative quantities never get here: QuantityType refuses them.
        if ($quantity > Quantity::units(self::MAX_QUANTITY)) {
            return $product->getUnit()->isWeighed()
                ? \sprintf('Choisis un poids entre 0 et %d kg.', self::MAX_QUANTITY)
                : \sprintf('Choisis une quantité entre 0 et %d.', self::MAX_QUANTITY);
        }

        if ($steps ? $product->acceptsOrderQuantity($quantity) : $product->getUnit()->accepts($quantity)) {
            return null;
        }

        if (!$product->getUnit()->isWeighed()) {
            return 'Indique un nombre entier.';
        }

        return \sprintf(
            'Choisis un multiple de %s kg, %s kg au moins.',
            Quantity::decimal($product->getQuantityStep()),
            Quantity::decimal($product->getMinQuantity()),
        );
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => OrderFormData::class,
            'identity' => false,
            'email' => false,
            'email_optional' => false,
            'honeypot' => false,
            'require_items' => true,
            'terms' => false,
            'age_check' => false,
            'selling_steps' => true,
            'weights' => null,
            // The offers to order from (default: all of the sale's); a last-minute sale offers its leftovers only.
            'offers' => null,
            'constraints' => static fn (Options $options): array => [
                new Callback(static function (OrderFormData $data, ExecutionContextInterface $context) use ($options): void {
                    if (true === $options['require_items'] && !$data->hasItems()) {
                        $context->buildViolation('Choisis au moins un produit.')->addViolation();
                    }

                    $sale = $options['sale'];
                    if (true === $options['age_check'] && $sale instanceof Sale && !$data->adultCertified && $data->ordersAlcohol($sale)) {
                        $context->buildViolation('La vente d\'alcool est interdite aux mineurs : certifie avoir 18 ans ou plus pour commander une boisson alcoolisée.')
                            ->atPath('adultCertified')
                            ->addViolation();
                    }

                    if (true === $options['email_optional'] && !$data->isEmailGiven() && '' === trim($data->phone ?? '')) {
                        $context->buildViolation('Sans e-mail, indique au moins un téléphone pour joindre ce client.')
                            ->atPath('phone')
                            ->addViolation();
                    }

                    if (true === $data->delivery?->isHome() && '' === trim($data->deliveryAddress ?? '')) {
                        $context->buildViolation('Indique ton adresse pour la livraison à domicile.')
                            ->atPath('deliveryAddress')
                            ->addViolation();
                    }
                }),
            ],
        ]);
        $resolver->setRequired('sale');
        $resolver->setAllowedTypes('sale', Sale::class);
        $resolver->setAllowedTypes('identity', 'bool');
        $resolver->setAllowedTypes('email', 'bool');
        $resolver->setAllowedTypes('email_optional', 'bool');
        $resolver->setAllowedTypes('honeypot', 'bool');
        $resolver->setAllowedTypes('require_items', 'bool');
        $resolver->setAllowedTypes('terms', 'bool');
        $resolver->setAllowedTypes('age_check', 'bool');
        $resolver->setAllowedTypes('selling_steps', 'bool');
        $resolver->setAllowedTypes('weights', ['null', Order::class]);
        $resolver->setAllowedTypes('offers', ['null', 'array']);
    }
}
