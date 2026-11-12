<?php

declare(strict_types=1);

namespace App\Customer\Form\Model;

use App\Customer\Entity\Customer;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Data behind OfflineCustomerType: name, phone and, optionally, an e-mail address.
 */
final class OfflineCustomerData
{
    #[Assert\Length(max: 80)]
    public ?string $firstName = null;

    #[Assert\Length(max: 80)]
    public ?string $lastName = null;

    #[Assert\Length(max: 30)]
    public ?string $phone = null;

    #[Assert\Email(message: 'Cette adresse e-mail ne semble pas valide.')]
    #[Assert\Length(max: 180)]
    public ?string $email = null;

    public static function fromCustomer(Customer $customer): self
    {
        $data = new self();
        $data->firstName = $customer->getFirstName();
        $data->lastName = $customer->getLastName();
        $data->phone = $customer->getPhone();

        return $data;
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if ('' === trim($this->firstName ?? '').trim($this->lastName ?? '')) {
            $context->buildViolation('Indique au moins un prénom ou un nom.')->atPath('lastName')->addViolation();
        }

        if ('' === trim($this->email ?? '') && '' === trim($this->phone ?? '')) {
            $context->buildViolation('Sans e-mail, indique au moins un téléphone pour joindre ce client.')->atPath('phone')->addViolation();
        }

        if (null !== $this->email && Customer::isAnonymizedEmail($this->email)) {
            $context->buildViolation('Cette adresse e-mail ne semble pas valide.')->atPath('email')->addViolation();
        }
    }
}
