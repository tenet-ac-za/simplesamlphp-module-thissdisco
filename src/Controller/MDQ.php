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
use Symfony\Component\HttpFoundation\{Request, Response, JsonResponse};

/**
 * Implement a discojson style metadata query service that implements the
 * PyFF search extentions. Output is designed to be compatible with
 * https://github.com/IdentityPython/pyFF/blob/2.1.3/src/pyff/samlmd.py#L885
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
    }

    public function __invoke(Request $request, ?string $identifier): Response
    {
        return $this->mdq($request, $identifier);
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
        if (in_array($entity['metadata-set'], ['saml20-sp-remote', 'saml20-idp-remote'])) {
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

        if (array_key_exists('DiscoHints', $entity)) {
            if (
                array_key_exists('GeolocationHint', $entity['DiscoHints'])
                && preg_match('/^geo:([^,]+),([^,;]+)(;|$)/i', $entity['DiscoHints']['GeolocationHint'][0], $matches)
            ) {
                $data['geo'] = [
                    'lat' => $matches[1],
                    'long' => $matches[2],
                ];
            }
        }

        if (isset($entity['id'])) {
            $data['id'] = $entity['id'];
        } else {
            $data['id'] = '{SHA1}' . hash('sha1', $entity['entityid']);
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
     * Retrieve a specific entity
     *
     * @param array $md The metadata to search in.
     * @param string $identifier The entity hash to look for.
     * @return array The entity data.
     */
    protected function getEntity(array $md, string $identifier): array
    {
        if (preg_match('/^\{([^}]+)\}(\w+)$/', $identifier, $matches)) {
            /* we're using a transformed identifier */
            $hashAlgorithm = strtolower($matches[1]);
            $entityHash = $matches[2];

            if (!in_array($hashAlgorithm, hash_algos(), true)) {
                throw new Error\BadRequest(
                    'Invalid hash algorithm: {' . $hashAlgorithm . '}',
                );
            }

            foreach ($md as $entity) {
                $entityId = $entity['entityid'];
                $hashedEntityId = hash($hashAlgorithm, $entityId);
                if ($hashedEntityId === $entityHash) {
                    $entity['id'] = sprintf('{%s}%s', strtoupper($hashAlgorithm), $entityHash);
                    return $this->entityAsDiscoJSON($entity);
                }
            }
        } else {
            /* we're using the entityID */
            $identifier = preg_replace(
                '/^(https?):\/(?=[^\/])/', /* fixup any lost slash in the protocol */
                '\1://',
                $identifier,
            );
            foreach ($md as $entity) {
                if ($entity['entityid'] === $identifier) {
                    return $this->entityAsDiscoJSON($entity);
                }
            }
        }
        return [];
    }

    /**
     * Search for entities in the metadata
     *
     * @param array $md The metadata to search in.
     * @param string $query The query to search for.
     * @param string $entity_filter The entity filter to apply.
     * @return array The list of entities matching the query.
     */
    protected function searchEntities(array $md, ?string $query, ?string $entity_filter): array
    {
        $data = [];
        foreach ($md as $entity) {
            /* quickly get rid of entities that aren't the right type */
            if (
                !empty($entity_filter) &&
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
        }
        return $data;
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
        $md = [];
        try {
            /** @var \SimpleSAML\Module\saml\Auth\Source\SP $source */
            foreach ($this->authSource::getSourcesOfType('saml:SP') as $source) {
                $metadata = $source->getHostedMetadata();
                $md[$metadata['entityid']] = $metadata;
            }
        } catch (Exception $exception) {
            throw new Error\Error(Error\ErrorCodes::METADATA, $exception);
        }

        try {
            foreach (['saml20-idp-remote', 'saml20-sp-remote'] as $metadataSets) {
                $md = array_merge_recursive($md, $this->mdHandler->getList($metadataSets));
            }
        } catch (Exception $exception) {
            throw new Error\Error(Error\ErrorCodes::METADATA, $exception);
        }

        if ($identifier !== null) {
            /**
             * we have an identifier, so we want a specific entity
             */
            $identifier = preg_replace('/\.json$/', '', $identifier); /* remove .json suffix */
            $data = $this->getEntity($md, $identifier);
            Logger::debug(
                sprintf(
                    'MDQ: lookup for entity with identifier %s returned %s',
                    $identifier,
                    $data['entityID'] ?? '[NONE]',
                ),
            );
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

            $data = $this->searchEntities($md, $query, $entity_filter);
            Logger::debug(
                sprintf(
                    'MDQ: searching for %s entities matching "%s" returned %s results',
                    $entity_filter,
                    $query,
                    count($data),
                ),
            );
        }

        $response = new JsonResponse();
        $response->setData($data);
        if (empty($data)) {
            $response->setStatusCode(Response::HTTP_NOT_FOUND);
        }
        return $response;
    }
}
