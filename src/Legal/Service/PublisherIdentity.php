<?php

declare(strict_types=1);

namespace App\Legal\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Who publishes and hosts the platform (LCEN, art. 6 III), read from the LEGAL_* environment
 * variables: the legal pages, the e-mail footers and the reports to the operator use it.
 *
 * The committed .env holds placeholders containing "à renseigner": `isPlaceholder()` spots them, and
 * docker/entrypoint.sh refuses to start in production while one is left.
 */
final readonly class PublisherIdentity
{
    /** Marker of the development values committed in .env. */
    public const string PLACEHOLDER_MARKER = 'à renseigner';

    public function __construct(
        #[Autowire(env: 'LEGAL_PUBLISHER_NAME')]
        public string $name,
        #[Autowire(env: 'LEGAL_PUBLISHER_FORM')]
        public string $legalForm,
        #[Autowire(env: 'LEGAL_PUBLISHER_ADDRESS')]
        public string $address,
        #[Autowire(env: 'LEGAL_PUBLISHER_SIRET')]
        public string $siret,
        #[Autowire(env: 'LEGAL_PUBLISHER_PHONE')]
        public string $phone,
        #[Autowire(env: 'LEGAL_PUBLICATION_DIRECTOR')]
        public string $publicationDirector,
        #[Autowire(env: 'LEGAL_CONTACT_EMAIL')]
        public string $contactEmail,
        #[Autowire(env: 'LEGAL_HOST_NAME')]
        public string $hostName,
        #[Autowire(env: 'LEGAL_HOST_ADDRESS')]
        public string $hostAddress,
        #[Autowire(env: 'LEGAL_HOST_PHONE')]
        public string $hostPhone,
        #[Autowire(env: 'LEGAL_SOURCE_CODE_URL')]
        public string $sourceCodeUrl,
    ) {
    }

    /**
     * Is one of the values still a development placeholder?
     */
    public function isPlaceholder(): bool
    {
        return array_any([$this->name, $this->legalForm, $this->address, $this->phone, $this->publicationDirector, $this->hostName, $this->hostAddress], fn (string $value): bool => '' === trim($value) || str_contains(mb_strtolower($value), self::PLACEHOLDER_MARKER));
    }
}
