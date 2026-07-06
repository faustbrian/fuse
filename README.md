 [![GitHub Workflow Status][ico-tests]][link-tests]
 [![Latest Version on Packagist][ico-version]][link-packagist]
 [![Software License][ico-license]](LICENSE.md)
 [![Total Downloads][ico-downloads]][link-downloads]

 ------

 Fuse is a Laravel circuit breaker package for queue jobs. It helps
 protect workers from cascading failures by tracking service health,
 short-circuiting repeated outages, and reopening traffic when recovery
 succeeds.

 ## Requirements

 > **Requires [PHP 8.5+](https://php.net/releases/)** and Laravel 10+

 ## Installation

 ```bash
 composer require cline/fuse
 ```

 ## Documentation

 - **[Getting Started](DOCS.md#doc-docs-readme)** - Installation,
   configuration, and the core circuit-breaker flow
 - **[Job Middleware](DOCS.md#doc-docs-job-middleware)** - Protecting
   queue jobs with automatic release behavior
 - **[Attributes](DOCS.md#doc-docs-attributes)** - Declaring circuit
   breakers directly on jobs
 - **[Failure Classification](DOCS.md#doc-docs-failure-classification)** -
   Deciding which exceptions should count as outages
 - **[Recovery Strategies](DOCS.md#doc-docs-recovery-strategies)** -
   Customizing half-open recovery behavior
 - **[Configuration](DOCS.md#doc-docs-configuration)** - Service
   thresholds, windows, and cache settings
 - **[Commands](DOCS.md#doc-docs-commands)** - Inspecting and managing
   circuits from Artisan

 ## Change log

 Please see [CHANGELOG](CHANGELOG.md) for more information on what has
 changed recently.

 ## Contributing

 Please see [CONTRIBUTING](CONTRIBUTING.md) and
 [CODE_OF_CONDUCT](CODE_OF_CONDUCT.md) for details.

 ## Security

 If you discover any security related issues, please use the
 [GitHub security reporting form][link-security] rather than the issue
 queue.

 ## Credits

 - [Brian Faust][link-maintainer]
 - [All Contributors][link-contributors]

 ## License

 The MIT License. Please see [License File](LICENSE.md) for more
 information.

 [ico-tests]: https://github.com/faustbrian/fuse/actions/workflows/quality-assurance.yaml/badge.svg
 [ico-version]: https://img.shields.io/packagist/v/cline/fuse.svg
 [ico-license]: https://img.shields.io/badge/License-MIT-green.svg
 [ico-downloads]: https://img.shields.io/packagist/dt/cline/fuse.svg

 [link-tests]: https://github.com/faustbrian/fuse/actions
 [link-packagist]: https://packagist.org/packages/cline/fuse
 [link-downloads]: https://packagist.org/packages/cline/fuse
 [link-security]: https://github.com/faustbrian/fuse/security
 [link-maintainer]: https://github.com/faustbrian
 [link-contributors]: ../../contributors
