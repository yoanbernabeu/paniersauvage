<?php

declare(strict_types=1);

namespace App\Shop\Controller;

use App\Delivery\Geocoding\GeocodedPlace;
use App\Delivery\Geocoding\GeocoderUnavailableException;
use App\Delivery\Geocoding\PlaceSearch;
use App\Instance\InstanceSettings;
use App\Shop\Enum\SearchOutcome;
use App\Shop\Enum\ShopCategory;
use App\Shop\Repository\ShopRepository;
use App\Shop\Search\DirectoryCommune;
use App\Shop\Search\NearbyShop;
use App\Shop\Search\ProducerSearch;
use App\Shop\Search\SearchCounter;
use App\Shop\Search\SearchCriteria;
use App\Shop\Search\SearchMap;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The producer directory (SPEC §11): a secondary page where visitors find the producers that chose
 * to appear there, ranked by distance only, and one indexable page per town.
 */
#[Route('/trouver-un-producteur')]
final class DirectoryController extends AbstractController
{
    /** Radius of a town page around the centre of its pick-up points. */
    public const int COMMUNE_RADIUS_KM = 10;

    public function __construct(
        private readonly ProducerSearch $producerSearch,
        private readonly SearchMap $searchMap,
        private readonly ShopRepository $shops,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('', name: 'producer_directory', methods: ['GET'])]
    public function index(Request $request, PlaceSearch $placeSearch, RateLimiterFactoryInterface $producerSearchIpLimiter, SearchCounter $counter, InstanceSettings $instance): Response
    {
        $criteria = SearchCriteria::fromQuery($request->query);
        $response = new Response();
        $state = 'idle';
        $placeLabel = null;
        /** @var list<NearbyShop> $results */
        $results = [];
        $map = null;

        if (!$criteria->isEmpty()) {
            // Result pages change with every query: not for search engines.
            $response->headers->set('X-Robots-Tag', 'noindex');

            if (!$producerSearchIpLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
                $state = 'rate_limited';
                $response->setStatusCode(Response::HTTP_TOO_MANY_REQUESTS);
            } else {
                [$state, $placeLabel, $results, $center, $postcode] = $this->search($criteria, $placeSearch);
                if (null !== $outcome = SearchOutcome::tryFrom($state)) {
                    $counter->count($outcome, $postcode, $criteria->place);
                }
                if (null !== $center) {
                    $map = $this->searchMap->build($center[0], $center[1], $placeLabel, $criteria->radiusKm, $results);
                }
            }
        }

        // A territorial instance with a centre opens on the producers of its territory (no search counted).
        $territory = $instance->territory;
        $territoryCriteria = null;
        $center = 'idle' === $state ? $territory->center() : null;
        if (null !== $center) {
            [$latitude, $longitude] = $center;
            $territoryCriteria = new SearchCriteria(null, $latitude, $longitude, $territory->radiusKm);
            $results = $this->producerSearch->around($latitude, $longitude, $territory->radiusKm);
            $map = $this->searchMap->build($latitude, $longitude, $territory->name ?: $instance->name, $territory->radiusKm, $results);
        }

        return $this->render('shop/directory/index.html.twig', [
            'criteria' => $criteria,
            'territory_criteria' => $territoryCriteria,
            'state' => $state,
            'place_label' => $placeLabel,
            'results' => $results,
            'map' => $map,
            'radii' => ProducerSearch::RADII_KM,
            'max_radius' => max(ProducerSearch::RADII_KM),
            'categories' => ShopCategory::cases(),
            'communes' => 'idle' === $state ? $this->shops->findDirectoryCommunes($this->clock->now()) : [],
        ], $response);
    }

    #[Route('/{commune}', name: 'producer_directory_commune', requirements: ['commune' => '[a-z0-9]+(?:-[a-z0-9]+)*'], methods: ['GET'])]
    public function commune(string $commune): Response
    {
        $town = $this->shops->findDirectoryCommune($commune, $this->clock->now());
        // A town without any shop of the directory left has no page (and no thin page for search engines).
        if (!$town instanceof DirectoryCommune) {
            throw $this->createNotFoundException('No producer of the directory in this town.');
        }

        // The points of the town are within the radius: never an empty page.
        $results = $this->producerSearch->around($town->latitude, $town->longitude, self::COMMUNE_RADIUS_KM);

        return $this->render('shop/directory/commune.html.twig', [
            'commune' => $town,
            'criteria' => new SearchCriteria($town->city, null, null, self::COMMUNE_RADIUS_KM),
            'state' => 'results',
            'place_label' => $town->city,
            'results' => $results,
            'map' => $this->searchMap->build($town->latitude, $town->longitude, $town->city, self::COMMUNE_RADIUS_KM, $results),
            'radii' => ProducerSearch::RADII_KM,
            'max_radius' => max(ProducerSearch::RADII_KM),
            'categories' => ShopCategory::cases(),
        ]);
    }

    /**
     * State, name of the searched place, results, searched point (latitude, longitude), postcode of the place.
     *
     * @return array{0: string, 1: ?string, 2: list<NearbyShop>, 3: array{0: float, 1: float}|null, 4: ?string}
     */
    private function search(SearchCriteria $criteria, PlaceSearch $placeSearch): array
    {
        $latitude = $criteria->latitude;
        $longitude = $criteria->longitude;
        $label = $postcode = null;

        if (null !== $criteria->place) {
            try {
                $place = $placeSearch->find($criteria->place);
            } catch (GeocoderUnavailableException) {
                return ['unavailable', null, [], null, null];
            }

            if (!$place instanceof GeocodedPlace) {
                return [SearchOutcome::NotFound->value, null, [], null, null];
            }

            [$latitude, $longitude, $postcode] = [$place->latitude, $place->longitude, $place->postcode];
            $label = $place->city ?? $place->label;
        }

        if (null === $latitude || null === $longitude) {
            return [SearchOutcome::NotFound->value, null, [], null, null];
        }

        $results = $this->producerSearch->around($latitude, $longitude, $criteria->radiusKm, $criteria->filters);

        return [([] === $results ? SearchOutcome::Empty : SearchOutcome::Results)->value, $label, $results, [$latitude, $longitude], $postcode];
    }
}
