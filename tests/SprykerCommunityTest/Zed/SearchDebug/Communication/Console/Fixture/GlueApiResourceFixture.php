<?php

/**
 * This file is part of the spryker-community/search-debug package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchDebug\Communication\Console\Fixture;

/**
 * Stands in for the real generator-produced `Generated\Api\Storefront\CatalogSearchStorefrontResource`
 * once the schema merge has happened — {@see \SprykerCommunity\Zed\SearchDebug\Communication\Console\SearchDebugCheckInstallationConsole::checkGlueApiWiring()}
 * only calls `class_exists()`/`method_exists()`, so a same-shaped fixture with a `getSearchDebug()`
 * accessor is enough.
 */
class GlueApiResourceFixture
{
    /**
     * @return array<string, mixed>
     */
    public function getSearchDebug(): array
    {
        return [];
    }
}
