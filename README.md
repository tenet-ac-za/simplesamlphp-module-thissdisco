# simplesamlphp-module-thissdisco
SimpleSAMLphp module implementing The Identity Selector discovery service

This module aims to provide a SimpleSAMLphp-compatible discovery service that implements the of the RA-21 best practices and provides a user interface that will seem familiar to people who've used the SeamlessAccess <https://seamlessaccesss.org/> discovery service.

It's discovery user interface widget is derived from The Identity Selector's [thiss-js discovery service](https://github.com/theidentityselector/thiss-js), albeit reimplemented in PHP & Twig for compatibility with SimpleSAMLphp. The widget can be embedded into a standard SimpleSAMLphp theme or it can be separately themed to look like the SeamlessAccess / <use.thiss.io> user interface.

The client side is implemented using the [thiss-ds-js client libraries](https://github.com/TheIdentitySelector/thiss-ds-js). These are directly imported without changes, and thus the entire interface should be compatible with SeamlessAccess's persistance service. See the [advanced integration notes](https://seamlessaccess.atlassian.net/wiki/spaces/DOCUMENTAT/pages/38240282/Advanced+Integration) for how this might be achieved.
