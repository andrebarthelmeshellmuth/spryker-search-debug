# Changelog

All notable changes to this package are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Each version below also has a [GitHub release](../../releases) with the fuller write-up.

## [Unreleased]

## [1.4.1] - 2026-08-28

### Added
- `checkGlueApiWiring()` in `SearchDebugCheckInstallationConsole` — warns (never fails) when the
  additive `searchDebug` schema merge or the project-level `CatalogSearchStorefrontProvider` override
  is missing. 4 tests + `GlueApiResourceFixture`.

### Documented
- `extra.dependency-pins` note on why `symfony/security-guard: 5.4.0-BETA1` must stay in `require`
  (a resolution pin, no `src/` usage).
- OpenSearch 3.5 / Lucene 10.3 compatibility verified end-to-end; new `docs/opensearch-3.x-migration.md`.

## [1.4.0] - 2026-08-27

### Added
- Glue API (API Platform): additive `searchDebug` property on core's `catalog-search` resource
  (`GET /catalog-search`). Schema-only merge — surfacing the value needs a project-level Provider
  override; see README "Glue REST API".

### Fixed
- Corrected `composer.json` dependency declarations after a requires-vs-usage audit.
- Pinned `symfony/security-guard` to the one pre-release that supports Symfony 6.4/7 `security-core`,
  unblocking `spryker/api-platform` resolution.
- Applied Rector `IfToNullCoalescingAssignRector` (unpinned dev-tooling drift).

## [1.3.4] - 2026-08-23

### Changed
- CI: bumped `actions/checkout` v4 → v7.

## [1.3.3] - 2026-08-22

### Added
- Zed test suite covering `SearchDebugCheckInstallationConsole`.

## [1.3.2] - 2026-08-20

### Changed
- Restored README screenshots against search-feedback's fictional "Feldwerk" demo catalog.
- CI: added a `fixtures-sync` job diffing the bundled demo-catalog CSVs against search-feedback's
  canonical copy on every push/PR.

### Fixed
- A Rector finding.

## [1.3.1] - 2026-08-19

### Changed
- README states plainly that `spryker-community/*` is an independent, community-built namespace with no
  official Spryker affiliation.
- Fixed stale `spryker-search-debugger` repo references (CHANGELOG, SECURITY, composer.json) after the
  rename to `spryker-search-debug`.
- Updated the Packagist-namespace note.
- CI: added an `xmllint` job for ruleset XML; pinned dev tooling to stop CI drift.

## [1.3.0] - 2026-08-18

### Added
- Word-level analysis page: per-field source breakdown, tokenization word badges resolved from the real
  analyzer's own boundaries.
- SKU-lookup widget.
- Presentation-suite coverage for both.

### Changed
- Stopped committing Spryker's own generated `PageIndexMap.php`; it is regenerated standalone in CI.

### Fixed
- Three word-level-analysis bugs found in review.

## [1.2.3] - 2026-08-13

### Changed
- CI: `phpstan` level 8 gated via a standalone `composer phpstan-ci` variant; now a required check.

## [1.2.2] - 2026-08-13

### Changed
- CI: the Codeception "Portable" subset now runs standalone via a `tests/_ci-standalone` bootstrap.

## [1.2.1] - 2026-08-11

### Fixed
- Test fixture classes moved from `autoload-dev` to `autoload` so they resolve under a consuming shop's
  own autoloader.
- Two Presentation tests that asserted `fulltext_synonyms` content the demo shop does not configure are
  now fail-soft.
- 5 phpcs docblock mismatches in `CheckInstallationControllerTest`.
- README: documented the required `search-debug-product-wrapper` div; corrected the stale test count.

### Added
- Coverage for `ComponentConfigController` and `SearchDebugTwigPlugin` (both 0% → 100%).

## [1.2.0] - 2026-08-08

### Added
- Opt-in `--stacked` score-line layout modifier for long formulas (label and value on separate lines).
  Existing callers are unaffected.

## [1.1.0] - 2026-08-06

### Changed
- Restructured the SRP overlay's Text/Business signal breakdown.

### Added
- WebDriver Presentation suite (23 tests).

### Fixed
- `queryScore` is now read from a plain query's own top-level value when no `function_score` wrapped it
  (previously `null` whenever every business-signal metric was at weight 0).
- Token-analysis path resolver no longer mis-attributes an edge-ngram-truncated synonym to a wrong
  same-offset sibling.
- `findContainingToken()` cyclomatic complexity reduced 10 → 7 (pure decomposition).

## [1.0.2] - 2026-07-28

### Added
- `.github/CODEOWNERS`.
- Rector (`rector.php`: PHP-8.3 rule set + level-0 dead-code / code-quality), applied across `src/` and
  `tests/`.
- PHPStan level 8 config; all 9 findings fixed (nullable-narrowing gaps across 6 files).

## [1.0.1] - 2026-07-25

### Changed
- `IS_CHECK_INSTALLATION_PAGE_ENABLED` now defaults to `false` (was `true`) — matches the
  registration-only idiom of the rest of the package and Spryker core's own `WebProfiler` default.

### Fixed
- Missing `Acknowledgements` entry in the README table of contents.

## [1.0.0] - 2026-07-25

### Added
- Initial release: a permission-gated overlay on the storefront search results page showing per-product
  Elasticsearch/OpenSearch `_score`, which query tokens matched, and a one-click BM25 boost/idf/tf
  breakdown; every query token links to its own analysis-path trace. Explanation parsing
  cross-validated against OpenSearch 1.3.4, OpenSearch 2.11, and Elasticsearch 8.11. Standalone by
  design — no hard dependency on any other community package.

[Unreleased]: ../../compare/v1.4.1...HEAD
[1.4.1]: ../../releases/tag/v1.4.1
[1.4.0]: ../../releases/tag/v1.4.0
[1.3.4]: ../../releases/tag/v1.3.4
[1.3.3]: ../../releases/tag/v1.3.3
[1.3.2]: ../../releases/tag/v1.3.2
[1.3.1]: ../../releases/tag/v1.3.1
[1.3.0]: ../../releases/tag/v1.3.0
[1.2.3]: ../../releases/tag/v1.2.3
[1.2.2]: ../../releases/tag/v1.2.2
[1.2.1]: ../../releases/tag/v1.2.1
[1.2.0]: ../../releases/tag/v1.2.0
[1.1.0]: ../../releases/tag/v1.1.0
[1.0.2]: ../../releases/tag/v1.0.2
[1.0.1]: ../../releases/tag/v1.0.1
[1.0.0]: ../../releases/tag/v1.0.0
