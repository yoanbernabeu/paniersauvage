<?php

declare(strict_types=1);

namespace App\Shop\Form;

use App\Shop\Entity\Shop;
use App\Shop\Enum\PaymentMethod;
use App\Shop\Enum\ShopCategory;
use App\Shop\Enum\VatRegime;
use App\Shop\Service\ImageStorage;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<Shop>
 */
final class ShopSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nom de ton activité',
                'empty_data' => '',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Présentation',
                'required' => false,
                'help' => 'Quelques lignes pour tes clients : ce que tu produis, comment tu travailles.',
                'attr' => ['rows' => 4],
            ])
            ->add('logo', FileType::class, [
                'label' => 'Logo',
                'mapped' => false,
                'required' => false,
                'help' => 'JPEG, PNG ou WebP, 5 Mo maximum.',
                'constraints' => [ImageStorage::constraint()],
                'attr' => ['accept' => implode(',', array_keys(ImageStorage::EXTENSIONS))],
            ])
            ->add('categories', EnumType::class, [
                'label' => 'Ce que tu vends',
                'class' => ShopCategory::class,
                'choice_label' => static fn (ShopCategory $category): string => $category->label(),
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'help' => 'Affiché sur ta page et dans l\'annuaire, où les clients peuvent filtrer par catégorie.',
            ])
            ->add('listedInSearch', CheckboxType::class, [
                'label' => 'Apparaître dans l\'annuaire des producteurs',
                'required' => false,
                'help' => 'Facultatif. Les visiteurs de « Trouver un producteur » te trouvent par la distance de tes points de rendez-vous, de tes arrêts de tournée ou de ta zone de livraison à domicile. Aucun classement payant : seule la distance compte. Il faut au moins une catégorie.',
            ])
            ->add('crossDiscoveryEnabled', CheckboxType::class, [
                'label' => 'Montrer d\'autres producteurs près de mes points de retrait',
                'required' => false,
                'help' => 'Sur ta page, la confirmation de commande et ses e-mails : au plus trois producteurs de l\'annuaire à moins de 10 km. Décoche pour ne montrer que ta boutique. Tu restes visible chez les autres si tu es dans l\'annuaire.',
            ])
            ->add('legalName', TextType::class, [
                'label' => 'Raison sociale',
                'required' => false,
                'help' => 'Si elle diffère du nom de ton activité. Affichée en bas de ta page, avec ton SIRET, ton adresse et tes contacts : c\'est obligatoire pour vendre à distance.',
                'attr' => ['autocomplete' => 'organization'],
            ])
            ->add('contactEmail', EmailType::class, [
                'label' => 'E-mail de contact',
                'required' => false,
                'help' => 'Affiché à tes clients. Laisse vide pour afficher l\'e-mail de ton compte.',
                'attr' => ['autocomplete' => 'email'],
            ])
            ->add('siret', TextType::class, [
                'label' => 'SIRET',
                'required' => false,
                'help' => 'Obligatoire pour apparaître dans l\'annuaire des producteurs : on le vérifie dans le répertoire public des entreprises. Il est affiché à tes clients.',
                'attr' => ['inputmode' => 'numeric', 'autocomplete' => 'off'],
            ])
            ->add('address', TextareaType::class, [
                'label' => 'Adresse',
                'required' => false,
                'attr' => ['rows' => 2, 'autocomplete' => 'street-address'],
            ])
            ->add('phone', TelType::class, [
                'label' => 'Téléphone',
                'required' => false,
                'attr' => ['autocomplete' => 'tel'],
            ])
            ->add('paymentMethods', EnumType::class, [
                'label' => 'Moyens de paiement acceptés',
                'class' => PaymentMethod::class,
                'choice_label' => static fn (PaymentMethod $method): string => $method->label(),
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'help' => 'Le règlement se fait directement avec toi : Panier Sauvage n\'encaisse rien et n\'enregistre aucun paiement.',
            ])
            ->add('localCurrencyName', TextType::class, [
                'label' => 'Nom de la monnaie locale',
                'required' => false,
                'attr' => ['placeholder' => 'La Mesure'],
            ])
            ->add('foodVoucherDetails', TextType::class, [
                'label' => 'Chèques ou caisse alimentaire acceptés',
                'required' => false,
                'attr' => ['placeholder' => 'Caisse alimentaire de Dieulefit'],
            ])
            ->add('paymentInfo', TextareaType::class, [
                'label' => 'Précisions sur le paiement',
                'required' => false,
                'help' => 'Texte libre affiché à tes clients, par exemple « Wero au 06 12 34 56 78, chèque à l\'ordre de … ».',
                'attr' => ['rows' => 2],
            ])
            ->add('vatRegime', EnumType::class, [
                'label' => 'Régime de TVA',
                'class' => VatRegime::class,
                'choice_label' => static fn (VatRegime $regime): string => $regime->label(),
                'expanded' => true,
                'help' => 'En franchise en base, tes commandes sont sans TVA et portent la mention « '.VatRegime::FRANCHISE_MENTION.' ». Le changement vaut pour les nouvelles commandes.',
            ])
            ->add('firstOrderCapCents', MoneyType::class, [
                'label' => 'Plafond d\'une première commande (€)',
                'required' => false,
                'currency' => false,
                'divisor' => 100,
                'input' => 'integer',
                'invalid_message' => 'Indique un montant en euros, par exemple 30.',
                'help' => 'Facultatif. Tant qu\'un client n\'a jamais retiré de commande chez toi, il ne peut pas commander plus en ligne : on l\'invite poliment à t\'appeler. Laisse vide pour ne rien limiter.',
                'attr' => ['inputmode' => 'decimal', 'placeholder' => '30'],
            ])
            ->add('newCustomerDeposit', TextType::class, [
                'label' => 'Acompte demandé aux nouveaux clients',
                'required' => false,
                'help' => 'Facultatif. Un texte affiché aux nouveaux clients sur la page de vente, le récapitulatif et l\'e-mail de confirmation. Le règlement se fait directement avec toi : Panier Sauvage n\'enregistre rien.',
                'attr' => ['placeholder' => '5 € par Wero à la commande', 'maxlength' => 200],
            ])
            ->add('allowCustomerEdit', CheckboxType::class, [
                'label' => 'Mes clients peuvent modifier ou annuler leur commande eux-mêmes',
                'required' => false,
            ])
            ->add('defaultEditCutoffHours', IntegerType::class, [
                'label' => 'Modification possible jusqu\'à (heures avant la date limite)',
                'required' => false,
                'help' => 'Laisse vide pour autoriser les modifications jusqu\'à la date limite de commande.',
                'attr' => ['min' => 0, 'inputmode' => 'numeric'],
            ])
        ;

        if (true === $options['has_logo']) {
            $builder->add('removeLogo', CheckboxType::class, [
                'label' => 'Retirer le logo actuel',
                'mapped' => false,
                'required' => false,
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Shop::class, 'has_logo' => false]);
        $resolver->setAllowedTypes('has_logo', 'bool');
    }
}
