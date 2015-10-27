# changelog of the boxalino Shopware plugin

## v1.1.6 Shopware 4 autocompletion design optimizations
* improved design of autocompletion suggestions in Shopware 4
* removed minimum search term length limitations

## v1.1.5 Shopware 4 autocompletion suggestions and transaction improvements
* Backported boxalino autocompletion suggestions for Shopware 4
* improved transaction tracking and export

## v1.1.4 Support pagination in generic recommendations
* Added optional parameter to offset the generic recommendations

## v1.1.3 Improving update functionality
* Added update functionality to avoid errors on existing cronjobs

## v1.1.2 Bugfixes for generic recommendations with context and Shopware 4
* Adapting context format if required for generic recommendations
* Disabling AJAX basket recommendations in shopware 4 due to compatibility issue
* Ensuring that enabling the export in shops with insufficient configuration
  doesn't interrupt exporter

## v1.1.1 Bugfixes for generic recommendations and translations
* Fixing item context in generic recommendation
* Changing the way translations and locales are connected, required for setups
  using localized languages (i.e. fr_CH instead of fr)
* solving translation fallback issue

## v1.1 Supporting Shopware Enterprise, Version 5, subshops and language shops

* Added support for Shopware 5
* Added support for Shopware Professional and Enterprise, including subshops
  and language shops

## v1.0 first release of the Shopware plugin

* Supports Shopware 4 Community
* Integrates boxalino search and recommendations to product pages
