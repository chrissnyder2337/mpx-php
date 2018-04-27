<?php

namespace Lullabot\Mpx\DataService;

use Cache\Adapter\PHPArray\ArrayCachePool;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Uri;
use Lullabot\Mpx\AuthenticatedClient;
use Lullabot\Mpx\DataService\Access\Account;
use Lullabot\Mpx\DataService\Annotation\DataService;
use Lullabot\Mpx\Encoder\CJsonEncoder;
use Lullabot\Mpx\Normalizer\UnixMicrosecondNormalizer;
use Lullabot\Mpx\Normalizer\UriNormalizer;
use Lullabot\Mpx\Service\AccessManagement\ResolveDomain;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Symfony\Component\PropertyInfo\PropertyInfoCacheExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\PropertyInfo\PropertyTypeExtractorInterface;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * Factory to construct new data service objects from MPX.
 *
 * @todo link to generic upstream docs.
 */
class DataObjectFactory
{
    /**
     * The resolver for MPX services.
     *
     * @var \Lullabot\Mpx\Service\AccessManagement\ResolveDomain
     */
    protected $resolveDomain;

    /**
     * The class and annotation to load data objects into.
     *
     * @var DiscoveredDataService
     */
    protected $dataService;

    /**
     * The client to make authenticated API calls.
     *
     * @var \Lullabot\Mpx\AuthenticatedClient
     */
    protected $authenticatedClient;

    /**
     * Cache to store reflection metadata from implementing clasess.
     *
     * @var CacheItemPoolInterface
     */
    protected $cacheItemPool;

    /**
     * DataObjectFactory constructor.
     *
     * @todo Inject the resolveDomain() instead of constructing?
     *
     * @param DiscoveredDataService             $dataService         The service to load data from.
     * @param \Lullabot\Mpx\AuthenticatedClient $authenticatedClient A client to make authenticated MPX calls.
     * @param CacheItemPoolInterface|null       $cacheItemPool       (optional) Cache to store reflection metadata.
     */
    public function __construct(DiscoveredDataService $dataService, AuthenticatedClient $authenticatedClient, CacheItemPoolInterface $cacheItemPool = null)
    {
        $this->authenticatedClient = $authenticatedClient;
        $this->resolveDomain = new ResolveDomain($this->authenticatedClient);
        $this->dataService = $dataService;

        if (!$cacheItemPool) {
            $cacheItemPool = new ArrayCachePool();
        }
        $this->cacheItemPool = $cacheItemPool;
    }

    /**
     * Load a data object from MPX, returning a promise to it.
     *
     * @param int                                      $id       The numeric ID to load.
     * @param \Lullabot\Mpx\DataService\Access\Account $account
     * @param bool                                     $readonly (optional) Load from the read-only service.
     *
     * @return PromiseInterface
     */
    public function loadByNumericId(int $id, Account $account = null, bool $readonly = false)
    {
        if (!$account) {
            $account = $this->authenticatedClient->getAccount();
        }

        $annotation = $this->dataService->getAnnotation();
        $base = $this->getBaseUri($account, $annotation, $readonly);

        $uri = new Uri($base.'/'.$id);

        return $this->load($uri);
    }

    /**
     * Deserialize a JSON string into a class.
     *
     * @todo Inject the serializer in the constructor?
     *
     * @param string $class The full class name to create.
     * @param string $data  The JSON string to deserialize.
     *
     * @return ObjectInterface
     */
    public function deserialize(string $class, $data)
    {
        // @todo Is this extractor required?
        $dataServiceExtractor = new DataServiceExtractor();
        $dataServiceExtractor->setClass($this->dataService->getClass());
        $p = new PropertyInfoExtractor([], [$dataServiceExtractor], [], []);
        $cached = new PropertyInfoCacheExtractor($p, $this->cacheItemPool);

        return $this->getObjectSerializer($cached)->deserialize($data, $class, 'json');
    }

    /**
     * Load an object from mpx.
     *
     * @param \Psr\Http\Message\UriInterface $uri The URI to load from. This URI will always be converted to https,
     *                                            making it safe to use directly from the ID of an mpx object.
     *
     * @return PromiseInterface A promise to return a \Lullabot\Mpx\DataService\ObjectInterface.
     */
    public function load(UriInterface $uri): PromiseInterface
    {
        /** @var DataService $annotation */
        $annotation = $this->dataService->getAnnotation();
        $options = [
            'query' => [
                'schema' => $annotation->schemaVersion,
                'form' => 'cjson',
            ],
        ];

        if ('http' == $uri->getScheme()) {
            $uri = $uri->withScheme('https');
        }

        $response = $this->authenticatedClient->requestAsync('GET', $uri, $options)->then(
            function (ResponseInterface $response) {
                return $this->deserialize($this->dataService->getClass(), $response->getBody());
            }
        );

        return $response;
    }

    /**
     * Query for MPX data using 'byField' parameters.
     *
     * @param ByFields $byFields The fields and values to filter by. Note these are exact matches.
     * @param Account  $account  (optional) The account context to use in the request. Defaults to the account
     *                           associated with the authenticated client.
     *
     * @return ObjectListIterator An iterator over the full result set.
     */
    public function select(ByFields $byFields, Account $account = null): ObjectListIterator
    {
        if (!$account) {
            $account = $this->authenticatedClient->getAccount();
        }

        return new ObjectListIterator($this->selectRequest($byFields, $account));
    }

    /**
     * Return a promise to an object list.
     *
     * @see \Lullabot\Mpx\DataService\DataObjectFactory::select
     *
     * @param ByFields $byFields The fields and values to filter by. Note these are exact matches.
     * @param Account  $account  The account context to use in the request.
     *
     * @return PromiseInterface A promise to return an ObjectList.
     */
    public function selectRequest(ByFields $byFields, Account $account): PromiseInterface
    {
        $annotation = $this->dataService->getAnnotation();
        $options = [
            'query' => $byFields->toQueryParts() + [
                'schema' => $annotation->schemaVersion,
                'form' => 'cjson',
                'count' => true,
            ],
        ];

        $uri = $this->getBaseUri($account, $annotation);

        $request = $this->authenticatedClient->requestAsync('GET', $uri, $options)->then(
            function (ResponseInterface $response) use ($byFields, $account) {
                $data = $response->getBody();

                /** @var ObjectList $list */
                $list = $this->getEntriesSerializer()->deserialize($data, ObjectList::class, 'json');
                $list->setByFields($byFields);
                $list->setDataObjectFactory($this, $account);

                return $list;
            }
        );

        return $request;
    }

    /**
     * Get the base URI from an annotation or service registry.
     *
     * @param Account     $account    The account to use for service resolution.
     * @param DataService $annotation The annotation data is being loaded for.
     * @param bool        $readonly   (optional) Load from the read-only service.
     *
     * @return string The base URI.
     */
    private function getBaseUri(Account $account, DataService $annotation, bool $readonly = false): string
    {
        // Accounts are optional as you need to be able to load an account
        // before you can resolve services.
        // @todo Can we do this by calling ResolveAllUrls?
        if (!($base = $annotation->getBaseUri())) {
            $resolved = $this->resolveDomain->resolve($account);

            $base = $resolved->getUrl($annotation->getService($readonly)).$annotation->getPath();
        }

        return $base;
    }

    private function getEntriesSerializer()
    {
        // @todo Should we just make multiple subclasses of ObjectList?
        // We need a property extractor that understands the varying types of 'entries'.
        $dataServiceExtractor = new DataServiceExtractor();
        $dataServiceExtractor->setClass($this->dataService->getClass());
        $p = new PropertyInfoExtractor([], [$dataServiceExtractor], [], []);
        $cached = new PropertyInfoCacheExtractor($p, $this->cacheItemPool);

        return $this->getObjectSerializer($cached);
    }

    /**
     * @param PropertyTypeExtractorInterface $dataServiceExtractor
     *
     * @return Serializer
     */
    private function getObjectSerializer(PropertyTypeExtractorInterface $dataServiceExtractor): Serializer
    {
        // First, we need an encoder that filters out null values.
        $encoders = [new CJsonEncoder()];

        // Attempt normalizing each key in this order, including denormalizing recursively.
        $normalizers = [
            new UnixMicrosecondNormalizer(),
            new UriNormalizer(),
            new ObjectNormalizer(
                null, null, null,
                $dataServiceExtractor
            ),
            new ArrayDenormalizer(),
        ];

        return new Serializer($normalizers, $encoders);
    }
}
