<?php

declare(strict_types=1);

namespace SimpleSAML\Module\thissdisco\Controller;

use Exception;
use SimpleSAML\Auth;
use SimpleSAML\Configuration;
use SimpleSAML\Error;
use SimpleSAML\Locale\Language;
use SimpleSAML\Logger;
use SimpleSAML\Metadata\MetaDataStorageHandler;
use SimpleSAML\Module\thissdisco\MDQCache;
use Symfony\Component\HttpFoundation\{Request, Response, JsonResponse};

/**
 * Metadata Query (MDQ) service Controller.
 *
 * Implement a discojson style metadata query service that implements the
 * PyFF search extentions and the thiss-mdq trustinfo filtering.
 * Output is designed to be compatible with
 * https://github.com/TheIdentitySelector/thiss-mdq/tree/1.5.8
 * with some localised extensions.
 *
 * @package SimpleSAMLphp
 */
class MDQ
{
    /** @var \SimpleSAML\Auth\Source|string */
    protected $authSource = Auth\Source::class;

    /** @var \SimpleSAML\Locale\Language */
    protected Language $language;

    /** @var \SimpleSAML\Metadata\MetaDataStorageHandler */
    protected MetadataStorageHandler $mdHandler;

    /** @var \SimpleSAML\Configuration The configuration for the module */
    private Configuration $moduleConfig;

    /** @var \SimpleSAML\Module\thissdisco\MDQCache hash -> entityID cache */
    private MDQCache $cache;

    /** @var int Negative cache time (entities not found) */
    private int $negativecachelength;

    /** @var int Maximum number of search results to return */
    private int $searchmax;

    /** @var array<string> the metadata sets we should concern ourselves with */
    private array $metadataSets = ['saml20-idp-remote', 'saml20-idp-hosted', 'saml20-sp-remote'];

    /**
     * Controller constructor.
     *
     * It initializes the global configuration for the controllers implemented here.
     *
     * @param \SimpleSAML\Configuration $config The configuration to use by the controllers.
     */
    public function __construct(
        protected Configuration $config,
    ) {
        if (!isset($this->config)) {
            $this->config = Configuration::getInstance();
        }
        $this->language = new Language($this->config);
        $this->mdHandler = MetaDataStorageHandler::getMetadataHandler();
        $this->moduleConfig = Configuration::getConfig('module_thissdisco.php');
        $this->cache = new MDQCache($this->config, $this->moduleConfig);
        $this->negativecachelength = $this->moduleConfig->getOptionalInteger('cachelength.negative', 3600);
        $this->searchmax = $this->moduleConfig->getOptionalInteger('search.maxresults', 0);
    }

    public function __invoke(Request $request, ?string $identifier): Response
    {
        return $this->mdq($request, $identifier);
    }

    /**
     * Generate a transformed identifier from an entityID
     *
     * @param string $entityId
     * @param string $hashAlgorithm (defaults to sha1)
     * @return string the corresponding transformed identifier
     */
    protected function getTransformedFromEntityId(string $entityId, string $hashAlgorithm = 'sha1'): string
    {
        /**
         * The blocks marked with cache-hash are the code required t store the
         * hash in the cache. However, we primarily use sha1 and it is probably
         * cheaper to recompute that than to store and retrieve it from a cache.
         * However, the inverse lookup (hash -> entityID) is VERY expensive, so
         * caching it when we do the forward calculation is a good idea.
         */
        /* // cache-hash
        $cacheEntity = $this->cache->get($entityId, []);
        if (isset($cacheEntity[$hashAlgorithm])) {
            return $cacheEntity[$hashAlgorithm];
        }
        */
        try {
            $hashedEntityId = hash($hashAlgorithm, $entityId);
        } catch (\ValueError $e) {
            throw new Error\BadRequest(
                'Invalid hash algorithm: {' . $hashAlgorithm . '}',
            );
        }
        $entityHash = sprintf('{%s}%s', strtoupper($hashAlgorithm), $hashedEntityId);
        /* // cache-hash
        $cacheEntity[$hashAlgorithm] = $entityHash;
        $this->cache->set($entityId, $cacheEntity);
        */
        /* save the inverse version for getEntityIdFromTransformed() */
        $this->cache->set($entityHash, $entityId);
        return $entityHash;
    }

    /**
     * Get an entityID from a cached transformed identifier
     *
     * @param string $identifer the transformed identifier
     * @return string|null the corresponding entityID (or null if not found)
     */
    protected function getEntityIdFromTransformed(string $identifer): ?string
    {
        if (preg_match('/^\{([^}]+)\}(\w+)$/', $identifer, $matches)) {
            /* if we're using a transformed identifier, normalise it */
            $identifer = sprintf('{%s}%s', strtoupper($matches[1]), $matches[2]);
            $hashAlgorithm = strtolower($matches[1]);

            if ($this->cache->has($identifer)) {
                return $this->cache->get($identifer);
            } else {
                /* we have to do an expensive search */
                foreach ($this->getMetadataList() as $entity) {
                    $entityId = $entity['entityid'];
                    $hashedEntityId = $this->getTransformedFromEntityId($entityId, $hashAlgorithm);
                    if ($hashedEntityId === $identifer) {
                        return $entity['entityid'];
                    }
                }
                /* save us from searching again for a hash that doesn't exist */
                $this->cache->set($identifer, null, $this->negativecachelength);
                return null;
            }
        }
        return $identifer;
    }

    /**
     * Convert a lanaguage array to a string
     *
     * @param ?array $data The data to filter by language.
     * @return string|array|null The entry corresponding to the default language.
     */
    protected function filterLangs(?array $data): mixed
    {
        /* like Translate::translateFromArray but handle other types */
        $currentLanguage = $this->language->getLanguage();
        if (isset($data[$currentLanguage])) {
            return $data[$currentLanguage];
        } elseif (isset($data[Language::FALLBACKLANGUAGE])) {
            return $data[Language::FALLBACKLANGUAGE];
        } elseif (is_array($data) && !empty($data)) {
            return current($data);
        }
        return null;
    }

    /**
     * Process the trust information / entity selection profile for an entity.
     * Based on the schema at https://github.com/TheIdentitySelector/thiss-mdq/blob/1.5.8/trustinfo.schema.json
     *
     * @param array $entity The entity to get the trust information for.
     * @return array The trust information
     */
    protected function getSelectionProfiles(array $entity): array
    {
        $selectionProfiles = [];
        if (
            array_key_exists('EntityAttributes', $entity)
            && array_key_exists('https://refeds.org/entity-selection-profile', $entity['EntityAttributes'])
        ) {
            try {
                $selectionProfiles = json_decode(
                    base64_decode(
                        $entity['EntityAttributes']['https://refeds.org/entity-selection-profile'][0],
                    ),
                    true,
                );
            } catch (Exception $e) {
                Logger::warning(
                    sprintf(
                        'MDQ: Failed to decode selection profile for %s: %s',
                        $entity['entityid'],
                        $e->getMessage(),
                    ),
                );
                $selectionProfiles = [];
            }
        }

        $globalSelectionProfiles = $this->moduleConfig->getOptionalArray('entity_selection_profiles', []);
        if (array_key_exists('profiles', $selectionProfiles) || !empty($globalSelectionProfiles)) {
            $selectionProfiles['profiles'] = array_merge(
                $globalSelectionProfiles,
                $selectionProfiles['profiles'],
            );
        }
        if (! empty($selectionProfiles)) {
            $selectionProfiles['entity_id'] = $entity['entityid'];
            $selectionProfiles['entityId'] = $entity['entityid'];
        }
        return $selectionProfiles;
    }

    /**
     * Converts the entity to a discojson data array.
     *
     * The schema for this is unclear, but probably goes back to discoJuice.
     * What's here is based on what PyFF and thiss-mdq output
     *
     * @param array $entity The entity to convert.
     * @return array The JSON data array.
     */
    protected function entityAsDiscoJSON(array $entity): array
    {
        $title = $entity['UIInfo']['DisplayName']
            ?? $entity['name']
            ?? $entity['OrganizationDisplayName']
            ?? $entity['OrganizationName']
            ?? [];
        $descr = $entity['UIInfo']['Description'] ?? $entity['description'] ?? [];
        $data['title'] = $this->filterLangs($title);
        $data['descr'] = $this->filterLangs($descr);
        $data['title_langs'] = $title;
        $data['descr_langs'] = $descr;
        if (str_contains($entity['metadata-set'], 'saml20')) {
            $data['auth'] = 'saml';
        } else {
            $data['auth'] = 'unknown';
        }
        $data['entity_id'] = $entity['entityid'];
        $data['entityID'] = $entity['entityid']; // per pyFF, but not in the spec

        if (array_key_exists('RegistrationInfo', $entity)) {
            $data['registrationAuthority'] = [$entity['RegistrationInfo']['authority']];
        }

        if (array_key_exists('EntityAttributes', $entity)) {
            if (array_key_exists('http://macedir.org/entity-category', $entity['EntityAttributes'])) {
                $data['entity_category'] = $entity['EntityAttributes']['http://macedir.org/entity-category'];
            }

            if (
                array_key_exists(
                    'urn:oasis:names:tc:SAML:attribute:assurance-certification',
                    $entity['EntityAttributes'],
                )
            ) {
                // phpcs:ignore
                $data['assurance_certification'] = $entity['EntityAttributes']['urn:oasis:names:tc:SAML:attribute:assurance-certification'];
            }

            if (array_key_exists('http://macedir.org/entity-category-support', $entity['EntityAttributes'])) {
                // phpcs:ignore
                $data['entity_category_support'] = $entity['EntityAttributes']['http://macedir.org/entity-category-support'];
            }
        }

        if (isset($entity['metarefresh:src'])) {
            $data['md_source'] = [
                $entity['metarefresh:src'],
                $entity['metadata-set'],
            ];
        } else {
            $data['md_source'] = [$entity['metadata-set']];
        }
        if (isset($entity['tags'])) {
            // make discopower-style tags available as sources
            foreach ($entity['tags'] as $tag) {
                array_push($data['md_source'], 'ssp-tag-' . $tag);
            }
            // and also as a schema extension
            $data['ssp_tags'] = $entity['tags'];
        }

        if (isset($entity['DiscoveryResponse'])) {
            $data['discovery_response'] = $entity['DiscoveryResponse'];
        }

        if (str_contains($entity['metadata-set'], '-idp-')) {
            $data['type'] = 'idp';
            if (
                in_array('http://refeds.org/category/hide-from-discovery', $data['entity_category'] ?? [], true)
                || boolval($entity['hide.from.discovery'] ?? false)
            ) {
                $data['hidden'] = 'true';
            } else {
                $data['hidden'] = 'false';
            }
        } elseif (str_contains($entity['metadata-set'], '-sp-')) {
            $data['type'] = 'sp';
        }

        if (isset($entity['scope'])) {
            $data['scope'] = implode(',', $entity['scope']);
            if (count($entity['scope']) == 1) {
                $data['domain'] = $entity['scope'][0];
                $data['name_tag'] = strtoupper(current(explode('.', $entity['scope'][0])));
            }
        }

        if (array_key_exists('UIInfo', $entity)) {
            if (isset($entity['UIInfo']['Logo'])) {
                $logo = $this->filterLangs($entity['UIInfo']['Logo']);
                $data['entity_icon_url']['url'] = $logo['url'];
                if (isset($logo['width'])) {
                    $data['entity_icon_url']['width'] = $logo['width'];
                }
                if (isset($logo['height'])) {
                    $data['entity_icon_url']['height'] = $logo['height'];
                }
            }

            if (isset($entity['UIInfo']['Keywords'])) {
                $data['keywords'] = implode(',', $this->filterLangs($entity['UIInfo']['Keywords']));
            }

            if (isset($entity['UIInfo']['PrivacyStatementURL'])) {
                $data['privacy_statement_url'] = $this->filterLangs($entity['UIInfo']['PrivacyStatementURL']);
            }
        }

        if (! isset($data['entity_icon_url']) && isset($entity['icon'])) {
            $data['entity_icon_url']['url'] = $entity['icon'];
        }

        if (
            array_key_exists('DiscoHints', $entity)
            && array_key_exists('GeolocationHint', $entity['DiscoHints'])
        ) {
            /** @var ?string */
            $GeolocationHint = is_array($entity['DiscoHints']['GeolocationHint'])
                ? $entity['DiscoHints']['GeolocationHint'][0]
                : $entity['DiscoHints']['GeolocationHint'];
            if (preg_match('/^geo:([^,]+),([^,;]+)(;|$)/i', $GeolocationHint ?? '', $matches)) {
                $data['geo'] = [
                    'lat' => $matches[1],
                    'long' => $matches[2],
                ];
            }
        }

        if (isset($entity['id'])) {
            $data['id'] = $entity['id'];
        } else {
            $data['id'] = $this->getTransformedFromEntityId($entity['entityid']);
        }

        if ($data['type'] === 'sp') {
            $selectionProfiles = $this->getSelectionProfiles($entity);
            if (! empty($selectionProfiles)) {
                $data['tinfo'] = $selectionProfiles;
            }
        }

        return $data;
    }

    /**
     * Retrieve a specific entity as discoJSON
     *
     * @param string $identifier The entity hash to look for.
     * @return array The entity data.
     */
    protected function getEntity(string $identifier): array
    {
        /* see if we're using a transformed identifier */
        $identifier = $this->getEntityIdFromTransformed($identifier);
        if ($identifier === null) {
            return [];
        }

        /* fixup any lost slash in the protocol due to Apache folding 'directories' */
        $identifier = preg_replace(
            '/^(https?):\/(?=[^\/])/',
            '\1://',
            $identifier,
        );

        /* this might be cheaper than searching, so do it early */
        $spMetadata = $this->getMetadataSP();
        if (isset($spMetadata[$identifier])) {
            return $this->entityAsDiscoJSON($spMetadata[$identifier]);
        }

        foreach ($this->metadataSets as $metadataSet) {
            try {
                $entity = $this->mdHandler->getMetaData($identifier, $metadataSet);
            } catch (Error\MetadataNotFound $e) {
                continue;
            }
            return $this->entityAsDiscoJSON($entity);
        }
        return [];
    }

    /**
     * Extension to getEntity() with trustinfo/entity selection handling
     *
     * @param string $identifier The entity hash to look for.
     * @param string $entityID The entity to source the trust profile.
     * @param string $trustProfileName The name of the trust profile
     * @return array The entity data.
     */
    protected function getEntityWithProfile(
        string $identifier,
        string $entityID,
        string $trustProfileName,
    ): array {
        $trustEntity = $this->getEntity($entityID);
        $entity = $this->getEntity($identifier);
        if (empty($trustEntity) || !isset($trustEntity['tinfo']['profiles'][$trustProfileName])) {
            return $entity;
        }
        if (!empty($entity) && $entity['type'] === 'sp') {
            return $entity;
        }

        $extraMetadata = $trustEntity['tinfo']['extra_md'] ?? [];
        $trustProfile = $trustEntity['tinfo']['profiles'][$trustProfileName];
        $strictProfile = boolval($trustProfile['strict']);

        $fromExtraMd = false;
        // first we check whether the entity comes from external metadata
        if (isset($extraMetadata[$identifier])) {
            $entity = $extraMetadata[$identifier];
            if (!isset($entity['entity_id'])) {
                $entity['entity_id'] = $identifier;
            }
            $fromExtraMd = true;
        }

        // if the entity is not in the internal or external metadata, return not found.
        if (empty($entity)) {
            return [];
        }
        $seen = null;
        if (array_key_exists('entity', $trustProfile)) {
            /*
             * I'm sure there's an easier/more efficient way to do this, but use the same logic as
             * https://github.com/TheIdentitySelector/thiss-mdq/blob/1.5.8/metadata.js#L269-L283
             */
            foreach ($trustProfile['entity'] as $e) {
                if ($seen === true) {
                    break;
                }
                $include = isset($e['include']) ? boolval($e['include']) : true;
                if ($include && $e['entity_id'] === $entity['entity_id']) {
                    $seen = true;
                } elseif ($include && $e['entity_id'] !== $entity['entity_id']) {
                    $seen = false;
                } elseif (!$include) {
                    if ($e['entity_id'] === $entity['entity_id']) {
                        $seen = false;
                    } else {
                        $seen = true;
                    }
                }
            }
        }

        // if the entity comes from external metadata,
        // return it only if it was selectd by the entity clauses in the profile,
        // otherwise return not found
        if ($fromExtraMd) {
            if ($seen !== false) {
                $entity['hint'] = true;
                return $entity;
            } else {
                return [];
            }
        }

        // check whether the entity is selected by some entities clause in the profile
        $passed = 0;
        $to_pass = 0;
        if ($seen !== false && array_key_exists('entities', $trustProfile)) {
            $to_pass = count($trustProfile['entities']);
            foreach ($trustProfile['entities'] as $e) {
                if (!array_key_exists('match', $e) || !array_key_exists($e['match'], $entity)) {
                    continue;
                }
                $include = isset($e['include']) ? boolval($e['include']) : true;
                if (is_array($entity[$e['match']])) {
                    if ($include && in_array($e['select'], $entity[$e['match']])) {
                        $passed++;
                    } elseif (!$include && !in_array($e['select'], $entity[$e['match']])) {
                        $passed++;
                    }
                } else {
                    if ($include && $entity[$e['match']] === $e['select']) {
                        $passed++;
                    } elseif (!$include && $$entity[$e['match']] !== $e['select']) {
                        $passed++;
                    }
                }
            }
        }
        $selected = $seen !== false && $passed === $to_pass;
        if ($strictProfile) {
            // if the profile is strict, return the entity if it was selected by the profile,
            // and not found otherwise
            if ($selected) {
                return $entity;
            } else {
                return [];
            }
        } else {
            // if the profile is not strict, set the hint if the entity was not selected by the profile,
            // and return the entity.
            if ($selected) {
                $entity['hint'] = true;
            }
            return $entity;
        }
    }

    /**
     * Search for entities in the metadata
     *
     * @param string $query The query to search for.
     * @param string $entity_filter The entity filter to apply.
     * @return array The list of entities matching the query.
     */
    protected function searchEntities(?string $query, ?string $entity_filter): array
    {
        $data = [];
        $count = 0;
        foreach ($this->getMetadataList() as $entity) {
            /* quickly get rid of entities that aren't the right type */
            if (
                !empty($entity_filter) &&
                isset($entity['metadata-set']) &&
                !str_contains($entity['metadata-set'], '-' . $entity_filter . '-')
            ) {
                continue;
            }

            if (!empty($query)) {
                $found = false;
                foreach (
                    [
                        /* https://github.com/IdentityPython/pyFF/blob/2.1.3/src/pyff/store.py#L415-L422 */
                        $entity['UIInfo']['DisplayName'] ?? [],
                        $entity['name'] ?? [], /* ServiceName */
                        $entity['OrganizationDisplayName'] ?? [],
                        $entity['OrganizationName'] ?? [],
                        $entity['UIInfo']['Keywords'] ?? [],
                        $entity['scope'] ?? [],
                    ] as $searchable
                ) {
                    foreach ($searchable as $value) {
                        if (is_array($value)) {
                            $value = implode(' ', $value);
                        }
                        if (str_contains(strtolower($value), $query)) {
                            $found = true;
                            break 2;
                        }
                    }
                }

                if (!$found) {
                    continue;
                }
            }

            array_push(
                $data,
                $this->entityAsDiscoJSON($entity),
            );

            /* maximum number of search results */
            if ($this->searchmax != 0 && $count++ > $this->searchmax) {
                break;
            }
        }
        return $data;
    }

    /**
     * Extension to searchEntities() with trustinfo/entity selection handling
     *
     * @param string $query The query to search for.
     * @param string $entity_filter The entity filter to apply.
     * @param string $entityID The entity to source the trust profile.
     * @param string $trustProfileName The name of the trust profile
     * @return array The entity data.
     */
    protected function searchEntitiesWithProfile(
        ?string $query,
        ?string $entity_filter,
        ?string $entityID,
        string $trustProfileName,
    ): array {
        $trustEntity = $this->getEntity($entityID);
        $data = $this->searchEntities($query, $entity_filter);
        if (empty($trustEntity) || !isset($trustEntity['tinfo']['profiles'][$trustProfileName])) {
            return $data;
        }

        $extraMetadata = $trustEntity['tinfo']['extra_md'] ?? [];
        $trustProfile = $trustEntity['tinfo']['profiles'][$trustProfileName];
        $strictProfile = boolval($trustProfile['strict']);
        $returnEntities = [];
        $skipEntities = [];

        if (array_key_exists('entity', $trustProfile)) {
            foreach ($trustProfile['entity'] as $e) {
                if (array_key_exists($e['entity_id'], $extraMetadata)) {
                    $entity = $extraMetadata[$e['entity_id']];
                    $entity['id'] = $this->getTransformedFromEntityId($e['entity_id']);
                    $entity['entity_id'] = $e['entity_id'];
                    $found = null;
                    if (!empty($query)) {
                        // we still have to filter the entity from extra_md
                        $found = false;
                        foreach (
                            [
                                $entity['title'] ?? '',
                                implode(',', $entity['title_langs'] ?? []),
                                $entity['tags'] ?? '',
                                $entity['keywords'] ?? '',
                                $entity['scope'] ?? '',
                            ] as $searchable
                        ) {
                            if (str_contains(strtolower($searchable), $query)) {
                                $found = true;
                                break;
                            }
                        }
                    }
                    if ($found !== false) {
                        $data[] = $entity;
                        $returnEntities[] = $e['entity_id'];
                    }
                } else {
                    $include = isset($e['include']) ? boolval($e['include']) : true;
                    if ($include) {
                        $returnEntities[] = $e['entity_id'];
                    } else {
                        $skipEntities[] = $e['entity_id'];
                    }
                }
            }
        }

        if (array_key_exists('entities', $trustProfile)) {
            foreach ($data as $entity) {
                if (in_array($entity['entity_id'], $skipEntities)) {
                    continue;
                }
                $passed = 0;
                $to_pass = count($trustProfile['entities']);
                foreach ($trustProfile['entities'] as $e) {
                    if (!array_key_exists('match', $e) || !array_key_exists($e['match'], $entity)) {
                        continue;
                    }
                    $include = isset($e['include']) ? boolval($e['include']) : true;
                    if (is_array($entity[$e['match']])) {
                        if ($include && in_array($e['select'], $entity[$e['match']])) {
                            $passed++;
                        } elseif (!$include && !in_array($e['select'], $entity[$e['match']])) {
                            $passed++;
                        }
                    } else {
                        if ($include && $entity[$e['match']] === $e['select']) {
                            $passed++;
                        } elseif (!$include && $$entity[$e['match']] !== $e['select']) {
                            $passed++;
                        }
                    }
                }
                if ($passed === $to_pass) {
                    $returnEntities[] = $entity['entity_id'];
                }
            }
        }

        if ($strictProfile) {
            return array_values(
                array_filter(
                    $data,
                    fn($e) => in_array($e['entity_id'], $returnEntities),
                ),
            );
        } else {
            return array_map(
                function ($e) use ($returnEntities) {
                    if (in_array($e['entity_id'], $returnEntities)) {
                        $e['hint'] = true;
                    }
                    return $e;
                },
                $data,
            );
        }

        return $data;
    }


    /**
     * Get hosted SP metadata
     *
     * @return array The metadata
     */
    protected function getMetadataSP(): array
    {
        $md = [];
        try {
            /** @var \SimpleSAML\Module\saml\Auth\Source\SP $source */
            foreach ($this->authSource::getSourcesOfType('saml:SP') as $source) {
                $metadata = $source->getHostedMetadata();
                $metadata['metadata-set'] ??= 'saml20-sp-remote';
                $metadata['metadata-index'] ??= $metadata['entityid'];
                $md[$metadata['entityid']] = $metadata;
            }
        } catch (Exception $exception) {
            throw new Error\Error(Error\ErrorCodes::METADATA, $exception);
        }
        return $md;
    }

    /**
     * Get a list of all possible metadata
     *
     * This is very expensive!
     *
     * @return array The metadata
     */
    protected function getMetadataList(): array
    {
        $md = $this->getMetadataSP();
        try {
            foreach ($this->metadataSets as $metadataSet) {
                $setMd = array_map(
                    function (array $x) use ($metadataSet) {
                        /* we assume that metadata contains a metadata-set,
                         * which is added by getMetadata() but not getList() */
                        $x['metadata-set'] ??= $metadataSet;
                        return $x;
                    },
                    $this->mdHandler->getList($metadataSet),
                );
                $md = array_merge($md, $setMd);
            }
        } catch (Exception $exception) {
            throw new Error\Error(Error\ErrorCodes::METADATA, $exception);
        }
        return $md;
    }

    /**
     * @param \Symfony\Component\HttpFoundation\Request $request The current request.
     * @param string|null $identifier The entity hash to look for.
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    public function mdq(Request $request, ?string $identifier): JsonResponse
    {
        if (
            ! in_array('application/json', $request->getAcceptableContentTypes(), true)
            && ! $request->query->has('debug')
        ) {
            throw new Error\BadRequest(
                'This MDQ endpoint only supports application/json',
            );
        }

        $data = [];
        $entityID = $request->query->get('entityid', $request->query->get('entityID', null));
        $trustProfileName = $request->query->get('trustprofile', $request->query->get('trustProfile', null));

        $response = new JsonResponse();

        if ($identifier !== null) {
            /**
             * we have an identifier, so we want a specific entity
             */
            $identifier = preg_replace('/\.json$/', '', $identifier); /* remove .json suffix */
            if ($entityID === null || $trustProfileName === null) {
                $data = $this->getEntity($identifier);
            } else {
                $data = $this->getEntityWithProfile($identifier, $entityID, $trustProfileName);
                $response->setPrivate();
            }
            Logger::debug(
                sprintf(
                    'MDQ: lookup for entity with identifier %s returned %s',
                    $identifier,
                    $data['entityID'] ?? '[NONE]',
                ),
            );
            if (empty($data)) {
                $response->setStatusCode(Response::HTTP_NOT_FOUND);
            }
        } else {
            /**
             * no identifier, so we want (a subset of) all entities
             */
            $query = strtolower($request->query->get('q', $request->query->get('query', '')));
            // enable matching on scope
            if (str_contains($query, '@') && !str_ends_with($query, '@')) {
                $query = end(explode('@', $query));
            }
            $entity_filter = str_replace(
                '{http://pyff.io/role}',
                '',
                strtolower($request->query->get('entity_filter', 'idp')),
            );

            if ($entityID === null || $trustProfileName === null) {
                $data = $this->searchEntities($query, $entity_filter);
            } else {
                $data = $this->searchEntitiesWithProfile($query, $entity_filter, $entityID, $trustProfileName);
                $response->setPrivate();
            }
            Logger::debug(
                sprintf(
                    'MDQ: searching for %s entities matching "%s" returned %s results',
                    $entity_filter,
                    $query,
                    count($data),
                ),
            );
        }

        $response->setData($data);
        if (empty($data)) {
            $response->setPrivate();
            $response->headers->addCacheControlDirective('no-store');
        } else {
            $response->setMaxAge($this->negativecachelength);
            $response->setSharedMaxAge($this->negativecachelength);
        }
        if ($request->query->has('debug')) {
            $response->setEncodingOptions(JsonResponse::DEFAULT_ENCODING_OPTIONS | JSON_PRETTY_PRINT);
        }
        return $response;
    }
}
