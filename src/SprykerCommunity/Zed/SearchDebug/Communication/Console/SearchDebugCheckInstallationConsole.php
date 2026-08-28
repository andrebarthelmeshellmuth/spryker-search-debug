<?php

/**
 * This file is part of the spryker-community/search-debug package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchDebug\Communication\Console;

use Elastica\Client;
use Elastica\Query;
use Spryker\Client\SearchElasticsearch\SearchElasticsearchConfig;
use Spryker\Shared\Config\Config;
use Spryker\Shared\Kernel\KernelConstants;
use Spryker\Shared\SearchElasticsearch\ElasticaClient\ElasticaClientFactory;
use Spryker\Zed\Kernel\Communication\Console\Console;
use SprykerCommunity\Client\SearchDebug\SearchDebugClient;
use SprykerCommunity\Shared\SearchDebug\Plugin\SeeSearchDebugInfoPermissionPlugin;
use SprykerCommunity\Shared\SearchDebug\SearchDebugConfig;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Diagnoses a search-debug installation.
 *
 * Installing this package means wiring several independent things, and almost every one of them fails
 * SILENTLY when missed — the overlay simply does not appear, with nothing in any log to say why. That
 * turns a five-minute mistake into an afternoon of bisecting. This command checks each prerequisite it
 * can reach from the CLI and names the exact remedy for whatever is wrong.
 *
 * Deliberately honest about its own limits: it runs in Zed, so it cannot introspect the Yves DI
 * container or confirm that the storefront templates actually render the widget. It verifies that the
 * classes exist and that everything ELSE those steps depend on is in place, and says plainly which
 * checks it could not make.
 *
 * Complementary counterpart:
 * {@see \SprykerCommunity\Yves\SearchDebugWidget\Controller\CheckInstallationController} (the
 * `/search-debug/check-installation` page) closes exactly the Yves-side gap this command names above —
 * event listener, Twig function and widget route registration — by running from inside the real Yves DI
 * container. It does not re-check anything this command already covers (engine reachability, page index,
 * explain support); run both for a full picture.
 */
class SearchDebugCheckInstallationConsole extends Console
{
    /**
     * @var string
     */
    public const COMMAND_NAME = 'search-debug:check-installation';

    /**
     * @var string
     */
    public const COMMAND_DESCRIPTION = 'Diagnoses a search-debug installation: core namespace, plugin classes, search engine reachability, page index shape, and explain support.';

    /**
     * @var string
     */
    protected const CORE_NAMESPACE = 'SprykerCommunity';

    /**
     * @var string
     */
    protected const PAGE_SOURCE_IDENTIFIER = 'page';

    /**
     * Unlike search-feedback's own Glue resource, this package only ADDITIVELY merges a `searchDebug`
     * property onto core's `catalog-search` resource (spryker/catalog-search-rest-api) — the merged
     * schema is what this class name check confirms exists.
     *
     * @var string
     */
    protected const GLUE_API_RESOURCE_CLASS_NAME = 'Generated\\Api\\Storefront\\CatalogSearchStorefrontResource';

    /**
     * README, "Glue REST API": the merge alone is not enough — a project-level Provider override is
     * required to actually copy the value into the response (the merged schema only describes SHAPE).
     * Relative to `APPLICATION_ROOT_DIR`, shared with spryker-community/search-ranking's own
     * `randomImpact` property (both packages document registering this same override once).
     *
     * @var string
     */
    protected const GLUE_API_PROVIDER_OVERRIDE_RELATIVE_PATH = '/src/Pyz/Glue/CatalogSearchRestApi/Api/Storefront/Provider/CatalogSearchStorefrontProvider.php';

    /**
     * @var array<string>
     */
    protected array $failures = [];

    /**
     * @var array<string>
     */
    protected array $warnings = [];

    protected function configure(): void
    {
        $this->setName(static::COMMAND_NAME);
        $this->setDescription(static::COMMAND_DESCRIPTION);

        parent::configure();
    }

    /**
     * @phpcsSuppress SlevomatCodingStandard.Functions.UnusedParameter $input is mandated by the Console base class.
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->checkCoreNamespace($output);
        $this->checkPluginClasses($output);
        $this->checkSearchEngine($output);
        $this->checkGlueApiWiring($output);

        $output->writeln('');

        foreach ($this->warnings as $warning) {
            $output->writeln(sprintf('<comment>! %s</comment>', $warning));
        }

        if ($this->failures !== []) {
            foreach ($this->failures as $failure) {
                $output->writeln(sprintf('<error>✗ %s</error>', $failure));
            }

            return static::CODE_ERROR;
        }

        $output->writeln('<info>Everything checkable from the CLI is in place.</info>');
        $output->writeln('Not verifiable from Zed — Zed never bootstraps the Yves DI container, so it cannot confirm:');
        $output->writeln('  - Yves plugin registration (EventDispatcher + Twig dependency providers, and the widget routes)');
        $output->writeln('  - storefront template integration and the compiled frontend assets');
        $output->writeln('  - that a customer actually holds the permission');
        $output->writeln('');
        $output->writeln('The first and third of those ARE checkable from Yves: load /search-debug/check-installation as a');
        $output->writeln('permitted customer (SprykerCommunity\Yves\SearchDebugWidget\Controller\CheckInstallationController).');
        $output->writeln('Template wiring and the frontend build remain a load-the-page check either way.');

        return static::CODE_SUCCESS;
    }

    /**
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    protected function checkCoreNamespace(OutputInterface $output): void
    {
        $coreNamespaces = Config::get(KernelConstants::CORE_NAMESPACES, []);

        if (in_array(static::CORE_NAMESPACE, $coreNamespaces, true)) {
            $output->writeln(sprintf('<info>✓</info> core namespace "%s" is registered', static::CORE_NAMESPACE));

            return;
        }

        $this->failures[] = sprintf(
            'Core namespace "%s" is NOT registered. Add it to KernelConstants::CORE_NAMESPACES in config/Shared/config_default.php — without it Spryker cannot resolve any of this package\'s classes.',
            static::CORE_NAMESPACE,
        );
    }

    /**
     * Class existence only — whether a project actually registered these is not visible from Zed. A
     * missing class means a broken install; a present class means "nothing is stopping you from
     * registering it".
     *
     * The Yves-layer plugins are deliberately NOT checked here: Spryker forbids a Zed file from
     * referencing the Yves namespace at all, and they ship in this same package anyway, so their
     * existence is already implied by the core-namespace check passing.
     *
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    protected function checkPluginClasses(OutputInterface $output): void
    {
        $requiredClasses = [
            'permission plugin' => SeeSearchDebugInfoPermissionPlugin::class,
            'search debug client' => SearchDebugClient::class,
        ];

        foreach ($requiredClasses as $label => $className) {
            if (class_exists($className)) {
                $output->writeln(sprintf('<info>✓</info> %s class is loadable', $label));

                continue;
            }

            $this->failures[] = sprintf('The %s (%s) could not be autoloaded.', $label, $className);
        }
    }

    /**
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    protected function checkSearchEngine(OutputInterface $output): void
    {
        try {
            $searchElasticsearchConfig = new SearchElasticsearchConfig();
            $elasticaClient = (new ElasticaClientFactory())->createClient($searchElasticsearchConfig->getClientConfig());
            $info = $elasticaClient->request('')->getData();
        } catch (Throwable $exception) {
            $this->failures[] = sprintf('Search engine is not reachable: %s', $exception->getMessage());

            return;
        }

        $version = $info['version'] ?? [];
        $output->writeln(sprintf(
            '<info>✓</info> search engine reachable: %s %s (Lucene %s)',
            (string)($version['distribution'] ?? 'elasticsearch'),
            (string)($version['number'] ?? '?'),
            (string)($version['lucene_version'] ?? '?'),
        ));

        $this->checkPageIndex($elasticaClient, $searchElasticsearchConfig, $output);
    }

    /**
     * @param \Elastica\Client $elasticaClient
     * @param \Spryker\Client\SearchElasticsearch\SearchElasticsearchConfig $searchElasticsearchConfig
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    protected function checkPageIndex(Client $elasticaClient, SearchElasticsearchConfig $searchElasticsearchConfig, OutputInterface $output): void
    {
        $indexPrefix = $searchElasticsearchConfig->getIndexPrefix();

        try {
            $aliases = $elasticaClient->request('_aliases')->getData();
        } catch (Throwable $exception) {
            $this->warnings[] = sprintf('Could not list indexes (%s) — skipping page index checks.', $exception->getMessage());

            return;
        }

        $pageIndexes = [];

        foreach (array_keys($aliases) as $indexName) {
            if (!str_starts_with((string)$indexName, $indexPrefix) || !str_ends_with((string)$indexName, static::PAGE_SOURCE_IDENTIFIER)) {
                continue;
            }

            $pageIndexes[] = (string)$indexName;
        }

        if ($pageIndexes === []) {
            $this->failures[] = sprintf(
                'No "%s*...%s" index found. The catalog has not been exported yet — run the publish/sync pipeline before expecting debug output.',
                $indexPrefix,
                static::PAGE_SOURCE_IDENTIFIER,
            );

            return;
        }

        $output->writeln(sprintf('<info>✓</info> page index found: %s', implode(', ', $pageIndexes)));

        $this->checkExplainSupport($elasticaClient, $pageIndexes[0], $output);
    }

    /**
     * The whole package rests on Elasticsearch's `explain` output being available and shaped the way the
     * parser expects, so this fires a real explained query rather than assuming.
     *
     * @param \Elastica\Client $elasticaClient
     * @param string $indexName
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    protected function checkExplainSupport(Client $elasticaClient, string $indexName, OutputInterface $output): void
    {
        try {
            $query = new Query();
            $query->setExplain(true);
            $query->setSize(1);

            $resultSet = $elasticaClient->getIndex($indexName)->search($query);
        } catch (Throwable $exception) {
            $this->failures[] = sprintf('An explained query against "%s" failed: %s', $indexName, $exception->getMessage());

            return;
        }

        if ($resultSet->count() === 0) {
            $this->warnings[] = sprintf('Index "%s" is empty, so explain output could not be confirmed.', $indexName);

            return;
        }

        $explanation = $resultSet->getResults()[0]->getExplanation();

        if ($explanation === []) {
            $this->failures[] = sprintf('Query against "%s" returned no _explanation — the overlay would show no score breakdown.', $indexName);

            return;
        }

        $output->writeln('<info>✓</info> explain output is available and non-empty');
    }

    /**
     * Two independent things have to both be true for `searchDebug` to actually appear on
     * `GET /catalog-search` (README, "Glue REST API"), and only the FIRST is something core/this
     * package's own composer install guarantees:
     *
     * 1. The additive schema merge ran: `Generated\Api\Storefront\CatalogSearchStorefrontResource` (core's
     *    resource, merged with this package's own `resources/api/storefront/catalog-search.resource.yml`)
     *    has a `getSearchDebug()` accessor. A project on a composer PATH REPOSITORY install (this
     *    demoshop's own setup) can silently fail this even with everything else correct — Symfony's
     *    `Finder` does not descend into symlinked directories without `->followLinks()`, so this package's
     *    schema file is invisible to `glue api:generate` unless the project registered
     *    `Pyz\Glue\ApiPlatformSymlinkFix\{SchemaFinder,ValidationSchemaFinder}` (see README).
     * 2. A project-level Provider override actually copies the value in at request time — the merged
     *    schema only describes SHAPE, nothing in either package populates the response without it. This
     *    is NOT optional in the sense frozen replay is optional elsewhere in this command family: a
     *    project that has done step 1 (installed `spryker/api-platform` and merged the schema) almost
     *    certainly intends `searchDebug` to actually work, so a missing override here is worth a WARNING
     *    either way — but neither half can be a FAILURE, since a project that does not run a Glue
     *    Storefront application at all is a legitimate, common configuration.
     *
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    protected function checkGlueApiWiring(OutputInterface $output): void
    {
        $resourceClassName = $this->getGlueApiResourceClassName();

        if (!class_exists($resourceClassName) || !method_exists($resourceClassName, 'getSearchDebug')) {
            $this->warnings[] = sprintf(
                '%s does not have a getSearchDebug() accessor yet: either `vendor/bin/glue api:generate storefront` has not been run since this package was installed, or (on a composer path-repository install) the Finder symlink-traversal fix from the README, "Glue REST API" is missing. GET /catalog-search will not include searchDebug until this is resolved. Skip this if your project does not run a Glue Storefront application.',
                $resourceClassName,
            );

            return;
        }

        $output->writeln(sprintf('<info>✓</info> Glue API schema merge: %s has a searchDebug property', $resourceClassName));

        $overrideFilePath = $this->getGlueApiProviderOverrideFilePath();

        if (!is_readable($overrideFilePath)) {
            $this->warnings[] = sprintf(
                'The schema merge is in place, but no project-level %s override exists (README, "Glue REST API"). The merged schema only describes SHAPE — without this override, searchDebug is silently omitted from every GET /catalog-search response.',
                static::GLUE_API_PROVIDER_OVERRIDE_RELATIVE_PATH,
            );

            return;
        }

        $overrideFileContents = (string)file_get_contents($overrideFilePath);

        if (!str_contains($overrideFileContents, SearchDebugConfig::SEARCH_RESULT_KEY)) {
            $this->warnings[] = sprintf(
                '%s exists but does not reference "%s" — searchDebug is still silently omitted from GET /catalog-search (README, "Glue REST API").',
                static::GLUE_API_PROVIDER_OVERRIDE_RELATIVE_PATH,
                SearchDebugConfig::SEARCH_RESULT_KEY,
            );

            return;
        }

        $output->writeln('<info>✓</info> project-level CatalogSearchStorefrontProvider override wires searchDebug into the Glue response');
    }

    /**
     * Isolated as its own method so a test can override it to point at a fixture class name instead of
     * this host shop's real generated Glue resource.
     */
    protected function getGlueApiResourceClassName(): string
    {
        return static::GLUE_API_RESOURCE_CLASS_NAME;
    }

    /**
     * Isolated as its own method so a test can override it to point at a fixture file instead of this
     * host shop's real `src/Pyz/Glue/CatalogSearchRestApi/Api/Storefront/Provider/CatalogSearchStorefrontProvider.php`
     * — same seam-for-testability reasoning search-feedback's own
     * `getSearchElasticsearchFactoryOverrideFilePath()` uses.
     */
    protected function getGlueApiProviderOverrideFilePath(): string
    {
        return APPLICATION_ROOT_DIR . static::GLUE_API_PROVIDER_OVERRIDE_RELATIVE_PATH;
    }
}
