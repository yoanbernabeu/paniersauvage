<?php

declare(strict_types=1);

namespace App\Legal\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Constraints\IsTrue;

/**
 * The single box "I accept the terms of use", with links to the texts.
 *
 * @extends AbstractType<array{acceptTerms: bool}>
 */
final class TermsAcceptanceType extends AbstractType
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('acceptTerms', CheckboxType::class, self::checkboxOptions($this->urlGenerator, 'J\'accepte les'));
    }

    /**
     * Options of a mandatory "accept the terms" box, shared by the producer sign-up form.
     *
     * @return array<string, mixed>
     */
    public static function checkboxOptions(UrlGeneratorInterface $urlGenerator, string $lead): array
    {
        return [
            'label' => \sprintf(
                '%s <a href="%s" class="link" target="_blank" rel="noopener">conditions d\'utilisation</a> et l\'<a href="%s#sous-traitance" class="link" target="_blank" rel="noopener">accord de sous-traitance des données</a>, et j\'ai lu la <a href="%s" class="link" target="_blank" rel="noopener">politique de confidentialité</a>.',
                $lead,
                $urlGenerator->generate('legal_terms'),
                $urlGenerator->generate('legal_terms'),
                $urlGenerator->generate('legal_privacy'),
            ),
            'label_html' => true,
            'required' => true,
            'constraints' => [new IsTrue(message: 'Accepte les conditions d\'utilisation pour continuer.')],
        ];
    }
}
