# ThissDisco

ThissDisco is a replacement for the built-in discovery service that implements the RA-21 best practices and provides a user interface that will seem familiar to people who've used the SeamlessAccess <https://seamlessaccesss.org/> discovery service.

## Enable the module

In `config.php`, search for the `module.enable` key and set `thissdisco` to true:

```php
    'module.enable' => [
         'thissdisco' => true,
         …
    ],
```

## Configuration

ThissDisco expects to find its configuration in `config/module_thissdisco.php`. There is a sample configuration in [config](../config/) that can be copied and adapted.

### Config options

`basetemplate`
:   The base template to use. Can be one of `simplesamlphp` (to inherit from base.twig and SimpleSAMLphp's normal theming mechanisims) or `thissio` (to use a template derived from use.thiss.io / SeamlessAccess).

`mdq`
:   Set the location and configuration of the metadata query (MDQ) service. By default we use our own SimpleSAMLphp metadata via an internal endpoint. You probably only want to changes this in exceptional circumstances (and then you may be better off using SeamlessAccess). `mdq` should be an array containing the following keys:

>    `lookup_base`
>    :   The base URL to look up entities. The transformed identifier will be appended to this base, so it should include a trailing slash. e.g. <https://md.thiss.io/entities/>.
>
>    `search`
>    :   The URL for a PyFF-compatible search extension. Leave this unset to use `lookup_base`.

`persistence`
:   Configuration for the persistence service. Note that if you want to use the service.seamlessaccess.org (recommended), then you need to register as an advanced integration for API access. See <https://seamlessaccess.atlassian.net/wiki/spaces/DOCUMENTAT/pages/38240282/Advanced+Integration>. `persistence` should be an array containing the following keys:

>    `url`
>    :   The URL for the persistence service. e.g. <https://use.thiss.io/ps/>
>
>    `context`
>    :   A context to set at the persistence service. Defaults to thissdisco's class name.
>
>    `csp_images`
>    :   Alter the *Content-Security-Policy* header to allow external images? Can be `false` to disable, `true` to add data: http:, or a string defining the additional CSP to add to *image-src*.

`discovery_response_warning`
:   Display a warning when the return parameter is not a known discovery response URL. Should be `false` (no warning), `true` (default SA warning), or a URL to direct people to.

`learn_more_url`
:   Optional URL to a page with further information. Defaults to the SeamlessAccess learn more page.

`search.maxresults`
:   Maximum number of results to return in a search (or 0 to disable the limit).

`useunsafereturn`
:   If you are configuring a protocol bridge, setting this to `true` will parse the URL return parameter and use it to find trustProfile configuration in SP metadata on the other side of the bridge. Because it introduces a small risk, the default is `false`.

#### Caching options

The following options all affect caching of the (SHA1) hashes. This massively improves performance with large metadata sets, since we do not need to search the whole set every time we look up a single entity. However, it's use is optional and it is not necessary for small data sets.

`cachetype`
:   is one of the cache types supported by [Symfony's Cache Component](https://symfony.com/doc/current/components/cache.html). Can be one one of `filesystem`, `phpfiles`, `memcache`, `pdo`, `redis`, or `none`. Will default to `phpfiles` if the [opcache](https://www.php.net/manual/en/book.opcache.php) is enabled or `filesystem` otherwise.

`cachedir`
:   means different things depending on driver. For filesystem based drivers, it does what you think. For database type drivers {redis, memcache, pdo} it is instead a DSN string (e.g. *redis://localhost* or *mysql:host=localhost;dbname=testdb*). If not set, will default to the cachedir setting in config.php.

`cachelength`
:   Sets the default maximum time (in seconds) an entry lives in the cache. Setting this to 0 means "keep for as long as possible", which depends on backend. Defaults to 0, which is sensible for the entityID <-> hash cache.

`cachelength.negative`
:   is the maximum length of time (in seconds) a non-existant entityID or hash will be remembered for. This avoids searching metadata again for an entity that will never be found, but means changes aren't picked up. Defaults to 3600 seconds which is a good compromise.

`crontags`
:   a list of cron tags when we should try to warm up the transformed identifier cache.

`cachelength.browser`
:   Somewhat separate to the other caching options, this affects how the resulting entities discoJSON MDQ objects might be stored in the client browser's local cache (HTTP Cache-Control max-age). Defaults to 43200 seconds.

#### Entity selection profile options

Entity selection profiles ("trust profiles") are used to filter the identity providers displayed in discovery results, either hiding the completely or hinting to users that they may not work as expected. ThissDisco implements TheIdentitySelector's trust profile language, as described at <https://seamlessaccess.atlassian.net/wiki/spaces/DOCUMENTAT/pages/1397686273/Filtering+search+results+-+indicating+which+IdP+s+are+supported>. Encoding is per the [REFEDS entity selection profile](https://refeds.org/entity-selection-profile) entity attribute.

`trustProfile`
:   The name of a default entity selection profile ("trust profile") to use if no other is given. This value can be overridden by setting `thissdisco.trustProfile` in the SP metadata, or by specifying the `trustProfile` parameter on the disco request. Note that the profile itself needs to be defined in module_thissdisco.php or in the SP's metadata.

`entity_selection_profiles`
:   An array of entity selection profile ("trust profile") descriptions to include in all SP metadata. This should be a PHP representation of the JSON that would normally be encoded in metadata (use json_encode() if you want to use the JSON form). Matching profiles declared in SP metadata take precidence over globally specified ones.

```php
    'entity_selection_profiles' => [
        'sirtfi' => [
            'entities' => [
                [
                    'include' => true,
                    'match' => 'assurance_certification',
                    'select' => 'https://refeds.org/sirtfi',
                ],
            ],
            'strict' => true,
        ],
    ],
```

## Enabling ThissDisco for a service

To enable the use of ThissDisco, you need to edit your [service provider configuration](https://simplesamlphp.org/docs/stable/simplesamlphp-sp) in `config/authsources.php` and set the `discoURL` parameter to point at the ThissDisco module:

```php
<?php
$config = [
    'default-sp' => [
        'saml:SP',
        'entityID' => …,
        …
        'discoURL' => '/simplesaml/module.php/thissdisco/disco',
    ],
];
```

This causes SimpleSAMLphp to use ThissDisco in preference to the built-in discovery interface.

## Accessing the DiscoJSON MDQ service

ThissDisco implements a DiscoJSON MDQ service, which it makes available at `<sspbasepath>/module.php/thissdisco/entities/`.

You can fetch a single entity directory using its SHA1 transformed identifier or its entityID.

You can fetch all entities matching a given query by specifying the `q=` query parameter. e.g.  <sspbasepath>/module.php/thissdisco/entities?q=sometexttosearchfor. It implements PyFF's search extensions (the `q=` and `entity_filter=` query parameters).

The search also implements the [thiss-mdq](https://github.com/TheIdentitySelector/thiss-mdq) "trustinfo" extensions to support a SeamlessAccess-compatible entity selection language (the `entityID=` and `trustProfile=` query parameters).

If you want to pretty print the resulting JSON for debugging purposes, add `debug=1` as a query parameter.
