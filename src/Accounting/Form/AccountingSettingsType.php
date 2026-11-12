<?php

declare(strict_types=1);

namespace App\Accounting\Form;

use App\Accounting\Entity\AccountingSettings;
use App\Accounting\Enum\AccountingCounterpart;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Accounts of the accounting entries export. Defaults: 706, 44571, 411 or 530, journal VE.
 *
 * @extends AbstractType<AccountingSettings>
 */
final class AccountingSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $account = static fn (string $label, string $default, string $help): array => [
            'label' => $label,
            'help' => \sprintf('Par défaut : %s. %s', $default, $help),
            'empty_data' => $default,
            'attr' => ['inputmode' => 'text', 'autocomplete' => 'off', 'spellcheck' => 'false', 'class' => 'font-mono'],
        ];

        $builder
            ->add('salesAccount', TextType::class, $account('Compte de ventes', AccountingSettings::DEFAULT_SALES_ACCOUNT, 'Ton comptable préfère peut-être 701 (produits finis) ou 707 (marchandises revendues).'))
            ->add('vatAccount', TextType::class, $account('Compte de TVA collectée', AccountingSettings::DEFAULT_VAT_ACCOUNT, 'Une ligne par taux dans chaque écriture. Inutile en franchise en base.'))
            ->add('counterpart', EnumType::class, [
                'label' => 'Compte débité',
                'class' => AccountingCounterpart::class,
                'choice_label' => static fn (AccountingCounterpart $counterpart): string => $counterpart->label(),
                'expanded' => true,
                'help' => 'Panier Sauvage n\'enregistre aucun paiement : l\'écriture dit ce que le client doit, pas comment il a payé.',
            ])
            ->add('customerAccount', TextType::class, $account('Compte client', AccountingSettings::DEFAULT_CUSTOMER_ACCOUNT, 'Un compte client générique, ou par exemple 411000 ou 411CLIENTS.'))
            ->add('cashAccount', TextType::class, $account('Compte de caisse', AccountingSettings::DEFAULT_CASH_ACCOUNT, 'Ou 531 si ton comptable distingue une seconde caisse.'))
            ->add('journalCode', TextType::class, $account('Code journal', AccountingSettings::DEFAULT_JOURNAL_CODE, 'Le journal des ventes.'));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AccountingSettings::class,
            'csrf_token_id' => 'accounting_settings',
        ]);
    }
}
