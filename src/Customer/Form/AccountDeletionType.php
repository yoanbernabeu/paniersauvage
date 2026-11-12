<?php

declare(strict_types=1);

namespace App\Customer\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Explicit confirmation before an account is erased.
 *
 * @extends AbstractType<array{confirm?: bool}>
 */
final class AccountDeletionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('confirm', CheckboxType::class, [
            'label' => 'Je comprends que c\'est définitif',
            'required' => true,
            'constraints' => [new Assert\IsTrue(message: 'Coche la case pour confirmer la suppression.')],
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'account_deletion';
    }
}
