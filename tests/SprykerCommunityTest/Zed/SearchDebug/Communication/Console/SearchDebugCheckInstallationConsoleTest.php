<?php

/**
 * This file is part of the spryker-community/search-debug package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchDebug\Communication\Console;

use Codeception\Test\Unit;
use SprykerCommunity\Zed\SearchDebug\Communication\Console\SearchDebugCheckInstallationConsole;
use SprykerCommunityTest\Zed\SearchDebug\Communication\Console\Fixture\GlueApiResourceFixture;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Every check here — core namespace, plugin class existence, search-engine reachability, page-index
 * shape, explain support — deliberately hits this demoshop's OWN real installation rather than a mock:
 * this command exists specifically to diagnose a REAL installation (see its own docblock), and it
 * constructs its own Elastica client directly rather than going through any injectable
 * Facade/Factory/Locator seam, so there is nothing to substitute even if a mock were desirable. This
 * demoshop is expected to be fully wired (core namespace registered, both required classes autoloadable,
 * a real reachable search engine with an exported page index that supports explain) — asserted on
 * accordingly, same portability tradeoff every sibling package's own CheckInstallationConsoleTest already
 * accepts.
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchDebug
 * @group Communication
 * @group Console
 * @group SearchDebugCheckInstallationConsoleTest
 * @group NeedsProject
 */
class SearchDebugCheckInstallationConsoleTest extends Unit
{
    public function testSucceedsAndReportsEveryCheckAgainstTheRealInstallation(): void
    {
        // Arrange
        $commandTester = $this->createCommandTester();

        // Act
        $exitCode = $commandTester->execute([]);

        // Assert
        $this->assertSame(SearchDebugCheckInstallationConsole::CODE_SUCCESS, $exitCode);
        $this->assertStringContainsString('core namespace "SprykerCommunity" is registered', $commandTester->getDisplay());
        $this->assertStringContainsString('permission plugin class is loadable', $commandTester->getDisplay());
        $this->assertStringContainsString('search debug client class is loadable', $commandTester->getDisplay());
        $this->assertStringContainsString('search engine reachable', $commandTester->getDisplay());
        $this->assertStringContainsString('page index found', $commandTester->getDisplay());
        $this->assertStringContainsString('explain output is available and non-empty', $commandTester->getDisplay());
        $this->assertStringContainsString('Everything checkable from the CLI is in place.', $commandTester->getDisplay());
    }

    public function testWarnsAndNamesTheRemedyWhenTheSchemaMergeHasNotHappened(): void
    {
        // Arrange — a class name nothing ever defines, standing in for the merged schema never having been generated.
        $commandTester = $this->createCommandTesterWithGlueApiWiring(
            resourceClassName: 'Generated\\Api\\Storefront\\DoesNotExistResource' . uniqid(),
            overrideFilePath: sys_get_temp_dir() . '/does-not-exist-' . uniqid() . '.php',
        );

        // Act
        $exitCode = $commandTester->execute([]);

        // Assert — optional (a project may not run Glue Storefront at all), so still CODE_SUCCESS.
        $this->assertSame(SearchDebugCheckInstallationConsole::CODE_SUCCESS, $exitCode);
        $this->assertStringContainsString('does not have a getSearchDebug() accessor yet', $commandTester->getDisplay());
        $this->assertStringNotContainsString('schema merge:', $commandTester->getDisplay());
        $this->assertStringNotContainsString('wires searchDebug into the Glue response', $commandTester->getDisplay());
    }

    public function testWarnsAndNamesTheRemedyWhenTheSchemaMergedButNoOverrideExists(): void
    {
        // Arrange
        $commandTester = $this->createCommandTesterWithGlueApiWiring(
            resourceClassName: GlueApiResourceFixture::class,
            overrideFilePath: sys_get_temp_dir() . '/does-not-exist-' . uniqid() . '.php',
        );

        // Act
        $exitCode = $commandTester->execute([]);

        // Assert
        $this->assertSame(SearchDebugCheckInstallationConsole::CODE_SUCCESS, $exitCode);
        $this->assertStringContainsString('has a searchDebug property', $commandTester->getDisplay());
        $this->assertStringContainsString('no project-level', $commandTester->getDisplay());
        $this->assertStringContainsString('override exists', $commandTester->getDisplay());
        $this->assertStringNotContainsString('wires searchDebug into the Glue response', $commandTester->getDisplay());
    }

    public function testWarnsAndNamesTheRemedyWhenTheOverrideExistsButDoesNotReferenceSearchDebug(): void
    {
        // Arrange
        $overrideFilePath = $this->createOverrideFileFixture('<?php class CatalogSearchStorefrontProvider {}');

        try {
            $commandTester = $this->createCommandTesterWithGlueApiWiring(
                resourceClassName: GlueApiResourceFixture::class,
                overrideFilePath: $overrideFilePath,
            );

            // Act
            $exitCode = $commandTester->execute([]);

            // Assert
            $this->assertSame(SearchDebugCheckInstallationConsole::CODE_SUCCESS, $exitCode);
            $this->assertStringContainsString('exists but does not reference "searchDebug"', $commandTester->getDisplay());
            $this->assertStringNotContainsString('wires searchDebug into the Glue response', $commandTester->getDisplay());
        } finally {
            unlink($overrideFilePath);
        }
    }

    public function testSucceedsWithoutWarningWhenTheGlueApiWiringIsComplete(): void
    {
        // Arrange
        $overrideFilePath = $this->createOverrideFileFixture('<?php class CatalogSearchStorefrontProvider { public function provideCollection() { $resourceData["searchDebug"] = $searchResult[SearchDebugConfig::SEARCH_RESULT_KEY] ?? []; } }');

        try {
            $commandTester = $this->createCommandTesterWithGlueApiWiring(
                resourceClassName: GlueApiResourceFixture::class,
                overrideFilePath: $overrideFilePath,
            );

            // Act
            $exitCode = $commandTester->execute([]);

            // Assert
            $this->assertSame(SearchDebugCheckInstallationConsole::CODE_SUCCESS, $exitCode);
            $this->assertStringContainsString('has a searchDebug property', $commandTester->getDisplay());
            $this->assertStringContainsString('wires searchDebug into the Glue response', $commandTester->getDisplay());
            $this->assertStringNotContainsString('does not have a getSearchDebug', $commandTester->getDisplay());
            $this->assertStringNotContainsString('no project-level', $commandTester->getDisplay());
        } finally {
            unlink($overrideFilePath);
        }
    }

    protected function createCommandTester(): CommandTester
    {
        $console = new SearchDebugCheckInstallationConsole();

        $application = new Application();
        $application->add($console);

        $command = $application->find(SearchDebugCheckInstallationConsole::COMMAND_NAME);

        return new CommandTester($command);
    }

    /**
     * Same wiring as {@see createCommandTester()}, but with an anonymous subclass overriding
     * {@see SearchDebugCheckInstallationConsole::getGlueApiResourceClassName()} and
     * {@see SearchDebugCheckInstallationConsole::getGlueApiProviderOverrideFilePath()} so the Glue API
     * wiring check tests fixtures instead of this host shop's real generated resource / real project
     * override file.
     */
    protected function createCommandTesterWithGlueApiWiring(string $resourceClassName, string $overrideFilePath): CommandTester
    {
        $console = new class ($resourceClassName, $overrideFilePath) extends SearchDebugCheckInstallationConsole {
            public function __construct(protected string $resourceClassName, protected string $overrideFilePath)
            {
                parent::__construct();
            }

            protected function getGlueApiResourceClassName(): string
            {
                return $this->resourceClassName;
            }

            protected function getGlueApiProviderOverrideFilePath(): string
            {
                return $this->overrideFilePath;
            }
        };

        $application = new Application();
        $application->add($console);

        $command = $application->find(SearchDebugCheckInstallationConsole::COMMAND_NAME);

        return new CommandTester($command);
    }

    /**
     * Writes a throwaway PHP file standing in for a project's
     * `src/Pyz/Glue/CatalogSearchRestApi/Api/Storefront/Provider/CatalogSearchStorefrontProvider.php`
     * override — the console only ever reads this file's contents with `file_get_contents()`, it never
     * includes/parses it, so the contents don't need to be autoload-safe PHP, just contain (or not
     * contain) the literal string `searchDebug`.
     */
    protected function createOverrideFileFixture(string $contents): string
    {
        $overrideFilePath = tempnam(sys_get_temp_dir(), 'catalog-search-storefront-provider-override-fixture-');

        file_put_contents($overrideFilePath, $contents);

        return $overrideFilePath;
    }
}
