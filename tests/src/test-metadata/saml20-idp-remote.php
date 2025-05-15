<?php // phpcs:ignoreFile

declare(strict_types=1);

$metadata['https://example.ac.za/idp'] = [
    'entityid' => 'https://example.ac.za/idp',
    'name' => ['en' => 'Example IdP',],
    'UIInfo' => [
        'Logo' => [
            [
                'url' => 'https://example.ac.za/logo-16x16.png',
                'height' => 16,
                'width' => 16,
            ],
        ],
    ],
    'contacts' => [
        [
            'contactType' => 'support',
            'emailAddress' => 'mailto:support@example.ac.za',
            'givenName' => 'Support Contact',
        ],
    ],
    'metadata-set' => 'saml20-idp-remote',
];

$metadata['https://example.org/idp'] = [
    'entityid' => 'https://example.org/idp',
    'name' => ['en' => 'Another Example IdP',],
    'contacts' => [
        [
            'contactType' => 'support',
            'emailAddress' => 'mailto:support@example.org.za',
            'givenName' => 'Support Contact',
        ],
    ],
    'metadata-set' => 'saml20-idp-remote',
];

$metadata['https://example.com/idp'] = [
    'entityid' => 'https://example.com/idp',
    'name' => [ 'en' => 'Example IdP',],
    'UIInfo' => [
        'DisplayName' => ['en' => 'Example IdP DisplayName'],
        'Logo' => [
            [
                'url' => 'https://example.com/logo-16x16.png',
                'height' => 16,
                'width' => 16,
            ],
        ],
        'Description' => ['en' => 'Description',],
        'PrivacyStatementURL' => ['en' => 'https://example.com/privacy',]
    ],
    'RegistrationInfo' => [
        'authority' => 'https://example.ac.za',
    ],
    'EntityAttributes' => [
        'http://macedir.org/entity-category' => [
            'http://refeds.org/category/hide-from-discovery',
        ],
        'http://macedir.org/entity-category-support' => [
            'http://refeds.org/category/research-and-scholarship',
        ],
        'urn:oasis:names:tc:SAML:attribute:assurance-certification' => [
            'https://refeds.org/sirtfi',
        ],
    ],
    'OrganizationDisplayName' => ['en' => 'Another OrganizationDisplayName',],
    'contacts' => [
        [
            'contactType' => 'support',
            'emailAddress' => 'mailto:support@example.com',
            'givenName' => 'Support Contact',
        ],
    ],
    'metarefresh:src' => 'test-metadata',
    'tags' => ['southafrica',],
    'scope' => ['example.com',],
    'metadata-set' => 'saml20-idp-remote',
];
