<?php

declare(strict_types=1);

namespace App\Legal\Form;

use App\Legal\Enum\ReportReason;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * Report of a shop: reason, message, optional e-mail to be answered, and a honeypot field.
 *
 * @extends AbstractType<array{reason: ?ReportReason, message: ?string, email: ?string, website: ?string}>
 */
final class ShopReportType extends AbstractType
{
    /** Hidden field only bots fill in: such a submission is dropped. */
    public const string HONEYPOT_FIELD = 'website';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('reason', EnumType::class, [
                'label' => 'Motif',
                'class' => ReportReason::class,
                'choice_label' => static fn (ReportReason $reason): string => $reason->label(),
                'expanded' => true,
                'constraints' => [new NotNull(message: 'Choisis un motif.')],
            ])
            ->add('message', TextareaType::class, [
                'label' => 'Ce qui ne va pas',
                'attr' => ['rows' => 5],
                'help' => 'Décris les faits : ce que tu as vu, quand, et si possible sur quelle vente.',
                'constraints' => [
                    new NotBlank(message: 'Explique-nous ce qui ne va pas.'),
                    new Length(min: 10, max: 2000, minMessage: 'Donne-nous un peu plus de détails ({{ limit }} caractères minimum).', maxMessage: 'Ton message est trop long ({{ limit }} caractères maximum).'),
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Ton e-mail (facultatif)',
                'required' => false,
                'help' => 'Seulement si tu veux qu\'on te réponde. Il n\'est pas transmis au producteur.',
                'attr' => ['autocomplete' => 'email', 'inputmode' => 'email'],
                'constraints' => [new Email(message: 'Cette adresse e-mail ne semble pas valide.'), new Length(max: 180)],
            ])
            ->add(self::HONEYPOT_FIELD, TextType::class, [
                'label' => 'Ne remplis pas ce champ',
                'required' => false,
                'attr' => ['tabindex' => '-1', 'autocomplete' => 'off'],
            ]);
    }
}
