<?php

declare(strict_types=1);

namespace App\Legal\Verification;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Looks a SIRET up in the API Recherche d'entreprises of the French State
 * (https://recherche-entreprises.api.gouv.fr, free, keyless, public data of the INSEE Sirene
 * register). Only the SIRET leaves the server: no personal data. The `company_registry.client`
 * scoped client carries a generic User-Agent and short timeouts.
 *
 * Answers are cached (`company_registry.cache`): a day when found or not found, never when the API
 * fails, so that a failure is retried.
 */
final readonly class CompanyRegistry
{
    private const int CACHE_TTL = 86400;

    /** NAF rev. 2 code, "10.71C"; anything else is dropped. */
    private const string NAF_FORMAT = '/^\d{2}\.\d{2}[A-Z]$/';

    public function __construct(
        private HttpClientInterface $companyRegistryClient,
        private CacheInterface $companyRegistryCache,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * The establishment with this SIRET, or null when the register does not know it.
     *
     * @throws CompanyRegistryUnavailableException
     */
    public function find(string $siret): ?CompanyRecord
    {
        if (1 !== preg_match('/^\d{14}$/', $siret)) {
            return null;
        }

        /** @var array{0: string, 1: ?string, 2: bool}|false $cached */
        $cached = $this->companyRegistryCache->get('siret_'.$siret, function (ItemInterface $item) use ($siret): array|false {
            $item->expiresAfter(self::CACHE_TTL);
            $record = $this->fetch($siret);

            return $record instanceof CompanyRecord ? [$record->companyName, $record->nafCode, $record->active] : false;
        });

        return false === $cached ? null : new CompanyRecord($siret, $cached[0], $cached[1], $cached[2]);
    }

    /**
     * @throws CompanyRegistryUnavailableException
     */
    private function fetch(string $siret): ?CompanyRecord
    {
        try {
            $response = $this->companyRegistryClient->request('GET', '/search', ['query' => ['q' => $siret, 'page' => 1, 'per_page' => 1]]);
            $status = $response->getStatusCode();

            if (200 !== $status) {
                $this->logger->warning('Company register answered with HTTP {status}.', ['status' => $status]);

                throw new CompanyRegistryUnavailableException(\sprintf('The company register answered with HTTP %d.', $status));
            }

            $data = $response->toArray();
        } catch (ExceptionInterface $exception) {
            $this->logger->warning('Company register unreachable: {message}', ['message' => $exception->getMessage(), 'exception' => $exception]);

            throw new CompanyRegistryUnavailableException('The company register is unreachable.', $exception->getCode(), previous: $exception);
        }

        if (!\is_array($data['results'] ?? null)) {
            throw new CompanyRegistryUnavailableException('Unexpected answer of the company register.');
        }

        foreach ($data['results'] as $company) {
            if (\is_array($company) && ($record = $this->recordFrom($company, $siret)) instanceof CompanyRecord) {
                return $record;
            }
        }

        return null;
    }

    /**
     * The establishment `$siret` of a company of the results (its head office, or one of the
     * "matching_etablissements"), or null when the company does not hold it.
     *
     * @param array<mixed> $company
     */
    private function recordFrom(array $company, string $siret): ?CompanyRecord
    {
        $establishments = \is_array($company['matching_etablissements'] ?? null) ? $company['matching_etablissements'] : [];
        if (\is_array($company['siege'] ?? null)) {
            $establishments[] = $company['siege'];
        }

        foreach ($establishments as $establishment) {
            if (!\is_array($establishment) || ($establishment['siret'] ?? null) !== $siret) {
                continue;
            }

            $name = $company['nom_complet'] ?? $company['nom_raison_sociale'] ?? null;
            $naf = $establishment['activite_principale'] ?? $company['activite_principale'] ?? null;

            return new CompanyRecord(
                $siret,
                \is_string($name) && '' !== $name ? mb_substr($name, 0, 255) : $siret,
                \is_string($naf) && 1 === preg_match(self::NAF_FORMAT, $naf) ? $naf : null,
                'A' === ($company['etat_administratif'] ?? null) && 'A' === ($establishment['etat_administratif'] ?? null),
            );
        }

        return null;
    }
}
