<?php

declare(strict_types=1);

namespace App\Accounting\Entity;

use App\Accounting\Enum\AccountingCounterpart;
use App\Accounting\Repository\AccountingSettingsRepository;
use App\Shop\Entity\Shop;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Accounts used by the accounting entries export of a shop. A shop without a row uses the defaults
 * (French chart of accounts): 706 sales, 44571 VAT collected, 411 customers or 530 cash, journal VE.
 */
#[ORM\Entity(repositoryClass: AccountingSettingsRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_accounting_settings_shop', columns: ['shop_id'])]
final class AccountingSettings
{
    public const string DEFAULT_SALES_ACCOUNT = '706';
    public const string DEFAULT_VAT_ACCOUNT = '44571';
    public const string DEFAULT_CUSTOMER_ACCOUNT = '411';
    public const string DEFAULT_CASH_ACCOUNT = '530';
    public const string DEFAULT_JOURNAL_CODE = 'VE';

    private const string ACCOUNT_PATTERN = '/^\d[0-9A-Z]{2,19}$/D';
    private const string ACCOUNT_MESSAGE = 'Un numéro de compte commence par un chiffre et compte de 3 à 20 chiffres ou lettres majuscules.';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    #[Assert\Regex(pattern: self::ACCOUNT_PATTERN, message: self::ACCOUNT_MESSAGE)]
    private string $salesAccount = self::DEFAULT_SALES_ACCOUNT;

    #[ORM\Column(length: 20)]
    #[Assert\Regex(pattern: self::ACCOUNT_PATTERN, message: self::ACCOUNT_MESSAGE)]
    private string $vatAccount = self::DEFAULT_VAT_ACCOUNT;

    #[ORM\Column(length: 20, enumType: AccountingCounterpart::class)]
    private AccountingCounterpart $counterpart = AccountingCounterpart::Customer;

    #[ORM\Column(length: 20)]
    #[Assert\Regex(pattern: self::ACCOUNT_PATTERN, message: self::ACCOUNT_MESSAGE)]
    private string $customerAccount = self::DEFAULT_CUSTOMER_ACCOUNT;

    #[ORM\Column(length: 20)]
    #[Assert\Regex(pattern: self::ACCOUNT_PATTERN, message: self::ACCOUNT_MESSAGE)]
    private string $cashAccount = self::DEFAULT_CASH_ACCOUNT;

    #[ORM\Column(length: 6)]
    #[Assert\Regex(pattern: '/^[A-Z0-9]{1,6}$/D', message: 'Le code journal compte de 1 à 6 lettres majuscules ou chiffres (souvent VE).')]
    private string $journalCode = self::DEFAULT_JOURNAL_CODE;

    public function __construct(
        #[ORM\OneToOne(targetEntity: Shop::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Shop $shop,
    ) {
    }

    /**
     * The account debited with the amount of each delivered order.
     */
    public function counterpartAccount(): string
    {
        return match ($this->counterpart) {
            AccountingCounterpart::Customer => $this->customerAccount,
            AccountingCounterpart::Cash => $this->cashAccount,
        };
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getShop(): Shop
    {
        return $this->shop;
    }

    public function getSalesAccount(): string
    {
        return $this->salesAccount;
    }

    public function setSalesAccount(string $salesAccount): static
    {
        $this->salesAccount = $this->normalize($salesAccount);

        return $this;
    }

    public function getVatAccount(): string
    {
        return $this->vatAccount;
    }

    public function setVatAccount(string $vatAccount): static
    {
        $this->vatAccount = $this->normalize($vatAccount);

        return $this;
    }

    public function getCounterpart(): AccountingCounterpart
    {
        return $this->counterpart;
    }

    public function setCounterpart(AccountingCounterpart $counterpart): static
    {
        $this->counterpart = $counterpart;

        return $this;
    }

    public function getCustomerAccount(): string
    {
        return $this->customerAccount;
    }

    public function setCustomerAccount(string $customerAccount): static
    {
        $this->customerAccount = $this->normalize($customerAccount);

        return $this;
    }

    public function getCashAccount(): string
    {
        return $this->cashAccount;
    }

    public function setCashAccount(string $cashAccount): static
    {
        $this->cashAccount = $this->normalize($cashAccount);

        return $this;
    }

    public function getJournalCode(): string
    {
        return $this->journalCode;
    }

    public function setJournalCode(string $journalCode): static
    {
        $this->journalCode = $this->normalize($journalCode);

        return $this;
    }

    /**
     * " 706 100 " → "706100", "ve" → "VE": spaces are a common way to type an account number.
     */
    private function normalize(string $value): string
    {
        return strtoupper(str_replace(' ', '', $value));
    }
}
