<?php

/**
 * This file is part of the spryker-community/search-debug package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Yves\SearchDebugWidget\Resolver;

use Generated\Shared\Search\PageIndexMap;
use Spryker\Client\ProductStorage\ProductStorageClientInterface;
use SprykerCommunity\Client\SearchDebug\SearchDebugClientInterface;

class TokenSourceResolver implements TokenSourceResolverInterface
{
    /**
     * The search resource whose documents this resolver reads — see
     * `vendor/spryker/product-page-search` (`spy_product_abstract_page_search` synchronization behavior).
     *
     * @var string
     */
    protected const RESOURCE_NAME_PRODUCT_ABSTRACT = 'product_abstract';

    /**
     * The abstract product ID never appears in the URL — only the SKU does, so this internal lookup type
     * is how the SKU from the query string gets turned back into the ID the rest of this class works with.
     *
     * @var string
     */
    protected const MAPPING_TYPE_SKU = 'sku';

    /**
     * Storage-document keys of the raw product data this resolver labels elements with. Public: also
     * referenced by {@see ProductSourceMapBuilder}, which reads the same two fields.
     *
     * @var string
     */
    public const STORAGE_KEY_NAME = 'name';

    /**
     * @var string
     */
    public const STORAGE_KEY_SKU = 'sku';

    /**
     * Label for document elements no known source AND no product attribute claims — e.g. a custom map
     * expander plugin contributing something this resolver has no way to identify.
     *
     * @var string
     */
    protected const LABEL_KEY_OTHER = 'search_debug.token_source.field.other';

    /**
     * Fallback tier label for a field $fieldBoosts reports that isn't one of the two well-known
     * `PageIndexMap` constants below (see {@link TIER_LABEL_KEYS}) — interpolates the raw field name and
     * boost directly rather than requiring a translation key per possible project-specific field name.
     *
     * @var string
     */
    protected const LABEL_KEY_TIER_GENERIC = 'search_debug.token_source.tier.generic';

    /**
     * Nicer labels for the two well-known `PageIndexMap` fields, when $fieldBoosts (the query's real,
     * live field=>boost pairs) reports them — actual tier ORDER at render time is boost-descending, driven
     * by $fieldBoosts, not by this list; a field not listed here still gets its own tier, with a generic
     * label (see {@link LABEL_KEY_TIER_GENERIC}), not silently dropped.
     *
     * @var array<string, string>
     */
    protected const TIER_LABEL_KEYS = [
        PageIndexMap::FULL_TEXT_BOOSTED => 'search_debug.token_source.tier.full_text_boosted',
        PageIndexMap::FULL_TEXT => 'search_debug.token_source.tier.full_text',
    ];

    /**
     * Memoizes `getTextTokenOffsets()` results per distinct element string — identical values recur in
     * one document (e.g. a concrete name equal to the abstract name) and need only one `_analyze` call.
     *
     * @var array<string, array<array{token: string, startOffset: int, endOffset: int}>>
     */
    protected array $tokenOffsetsCache = [];

    /**
     * @param \Spryker\Client\ProductStorage\ProductStorageClientInterface $productStorageClient
     * @param \SprykerCommunity\Client\SearchDebug\SearchDebugClientInterface $searchDebugClient
     * @param \SprykerCommunity\Yves\SearchDebugWidget\Resolver\TokenHighlighterInterface $tokenHighlighter
     * @param \SprykerCommunity\Yves\SearchDebugWidget\Resolver\ProductSourceMapBuilder $productSourceMapBuilder
     */
    public function __construct(
        protected ProductStorageClientInterface $productStorageClient,
        protected SearchDebugClientInterface $searchDebugClient,
        protected TokenHighlighterInterface $tokenHighlighter,
        protected ProductSourceMapBuilder $productSourceMapBuilder,
    ) {
    }

    /**
     * @param string $productAbstractSku
     * @param string $token
     * @param string $localeName
     * @param array<string, int> $fieldBoosts The query's real field=>boost pairs (see
     *   `SprykerCommunity\Client\SearchDebug\Query\QueryFieldBoostReaderInterface`). Empty means that
     *   couldn't be captured (e.g. a hand-typed URL, or a request whose search never went through
     *   `SearchDebugQueryExpanderPlugin`) — `tiers` is then empty too, rather than guessing at which
     *   fields a query might have used.
     *
     * @return array{
     *     productTitle: string,
     *     productSku: string,
     *     tiers: array<int, array{
     *         key: string,
     *         labelKey: string,
     *         boost: int,
     *         rows: array<int, \SprykerCommunity\Yves\SearchDebugWidget\Resolver\TokenSourceRow>,
     *     }>,
     * }|null
     */
    public function resolve(string $productAbstractSku, string $token, string $localeName, array $fieldBoosts = []): ?array
    {
        $productData = $this->productStorageClient->findProductAbstractStorageDataByMapping(
            static::MAPPING_TYPE_SKU,
            $productAbstractSku,
            $localeName,
        );

        if ($productData === null) {
            return null;
        }

        $documentData = $this->searchDebugClient->findPageDocumentData(
            static::RESOURCE_NAME_PRODUCT_ABSTRACT,
            (string)$productData['id_product_abstract'],
            $localeName,
        );

        $sourceMap = $this->productSourceMapBuilder->build($productData, $localeName);

        return [
            'productTitle' => (string)($productData[static::STORAGE_KEY_NAME] ?? ''),
            'productSku' => (string)($productData[static::STORAGE_KEY_SKU] ?? ''),
            'tiers' => $this->buildTiers(
                $documentData ?? [],
                $sourceMap['sourceKeysByValue'],
                $sourceMap['attributeLabelByValue'],
                $token,
                $fieldBoosts,
            ),
        ];
    }

    /**
     * Attributes each tier's real document elements: analyzes every element with the index-time
     * analyzer, marks the ones containing the token, and labels each element via the per-tier
     * value-to-source lookup — falling back to the generic "other indexed value" label for elements no
     * known source or attribute claims.
     *
     * Which tiers exist, and in what order, is driven entirely by $fieldBoosts — the query's real, live
     * field=>boost pairs — sorted boost-descending, not a fixed count: a query searching three fields
     * shows three tiers, one searching five shows five. No fallback: an empty $fieldBoosts means the
     * fields a query actually used could not be captured, and this legitimately has nothing to show
     * rather than guessing at a field list or a boost value that isn't real.
     *
     * @param array<string, mixed> $documentData
     * @param array<string, array<string, array<int, string>>> $sourceKeysByValue
     * @param array<string, array<int, string>> $attributeLabelByValue
     * @param string $token
     * @param array<string, int> $fieldBoosts
     *
     * @return array<int, array{
     *     key: string,
     *     labelKey: string,
     *     boost: int,
     *     rows: array<int, \SprykerCommunity\Yves\SearchDebugWidget\Resolver\TokenSourceRow>,
     * }>
     */
    protected function buildTiers(
        array $documentData,
        array $sourceKeysByValue,
        array $attributeLabelByValue,
        string $token,
        array $fieldBoosts,
    ): array {
        arsort($fieldBoosts);

        $elementsByTier = [];
        foreach ($fieldBoosts as $tier => $boost) {
            $elementsByTier[$tier] = array_map(static fn ($value): string => (string)$value, (array)($documentData[$tier] ?? []));
        }

        $this->warmTokenOffsetsCache(array_merge(...array_values($elementsByTier)));

        $tiers = [];
        foreach ($fieldBoosts as $tier => $boost) {
            $tiers[] = [
                'key' => $tier,
                'labelKey' => static::TIER_LABEL_KEYS[$tier] ?? static::LABEL_KEY_TIER_GENERIC,
                'boost' => $boost,
                'rows' => $this->buildTierRows(
                    $elementsByTier[$tier],
                    $sourceKeysByValue[$tier] ?? [],
                    $this->flattenSourceKeysAcrossTiers($sourceKeysByValue),
                    $attributeLabelByValue,
                    $token,
                ),
            ];
        }

        return $tiers;
    }

    /**
     * Pre-fetches token offsets for every element across every tier in ONE batched Elasticsearch call
     * (see {@see \SprykerCommunity\Client\SearchDebug\SearchDebugClientInterface::getTextTokenOffsetsForTexts()}),
     * instead of {@see buildTierRows()}/{@see findTokenMatches()} triggering one blocking `_analyze` call
     * per element as they go — a product with several variants can easily have 10-20+ distinct elements.
     *
     * Deliberately NOT a `$uncachedElements[$element] = true` dict with `array_keys()` at the end: PHP
     * silently coerces a purely-numeric STRING array key (e.g. an element that happens to read like a
     * plain number) to a real int key, so `array_keys()` would leak an `int` into `$uncachedElements`
     * where every OTHER entry is a `string` — a real, live-reproduced bug (a numeric-looking element
     * broke the batched analyze call with a `TypeError`, `int` given where `string` was expected). Same
     * class of coercion {@see splitByQueryTokens()} already documents guarding against elsewhere in this
     * package — `array_unique()` over a plain list compares by VALUE, never touches keys, so it can't
     * silently retype anything.
     *
     * @param array<int, string> $elements
     */
    protected function warmTokenOffsetsCache(array $elements): void
    {
        $uncachedElements = array_values(array_unique(array_filter(
            $elements,
            fn (string $element): bool => trim($element) !== '' && !isset($this->tokenOffsetsCache[$element]),
        )));

        if ($uncachedElements === []) {
            return;
        }

        $this->tokenOffsetsCache += $this->searchDebugClient->getTextTokenOffsetsForTexts($uncachedElements);
    }

    /**
     * One row per MATCHED document element (in canonical source-definition order, see
     * {@see ProductSourceMapBuilder::collectSourceKeysByValue()}) — each carries its own raw `element` text, so a caller (the
     * token-source page's magnifying-glass link) can open the analysis-path page for that EXACT element,
     * not a source in the abstract. A source with no matching element at all still gets ONE summary "no
     * match" row (nothing to link to there, so no reason to show one per element). A value two or more
     * sources both claim renders with multiple `labelKeys` instead of picking one and silently dropping
     * the rest — the caller is expected to join and display all of them, honestly communicating that the
     * value can't be attributed to just one. Elements no named source claims are matched against the
     * product's own attribute values next (label = the real attribute key); anything still unidentified
     * follows as one row each, ALWAYS showing their text — for those, the value itself is the diagnostic
     * information.
     *
     * @param array<int, string> $elements
     * @param array<string, array<int, string>> $sourceKeysByValue
     * @param array<string, array<int, string>> $sourceKeysByValueAnyTier
     * @param array<string, array<int, string>> $attributeLabelByValue
     * @param string $token
     *
     * @return array<int, \SprykerCommunity\Yves\SearchDebugWidget\Resolver\TokenSourceRow>
     */
    protected function buildTierRows(
        array $elements,
        array $sourceKeysByValue,
        array $sourceKeysByValueAnyTier,
        array $attributeLabelByValue,
        string $token,
    ): array {
        $matchedRowsByGroupKey = [];
        $sourceKeysByGroupKey = [];
        $otherRows = [];
        $seenElementsByGroupKey = [];

        foreach ($elements as $element) {
            if (trim($element) === '') {
                continue;
            }

            // Filtered through the SAME overlap rule highlight() itself applies internally, and stored as
            // THIS row's 'matches' below — never the raw, unfiltered list — so a row's magnifying-glass
            // links (built one per rendered `<mark>`, matched up by array position) can never drift out of
            // sync with which marks actually got rendered.
            $matches = $this->tokenHighlighter->filterRenderable($this->findTokenMatches($element, $token));
            // Declared tier first; if this element is not where SOURCE_DEFINITIONS says it should be,
            // fall back to the same value's definition from ANY tier. A project that moved a field
            // between full-text and full-text-boosted then still gets its real label instead of
            // degrading to "other indexed value" — the tier shown is the one the document actually
            // has, because this method is already called once per REAL document tier.
            $sourceKeys = $sourceKeysByValue[$element] ?? ($sourceKeysByValueAnyTier[$element] ?? []);

            if ($sourceKeys === []) {
                $this->addOtherRow($otherRows, $seenElementsByGroupKey, $attributeLabelByValue, $element, $matches);

                continue;
            }

            $this->registerNamedSourceElement($matchedRowsByGroupKey, $sourceKeysByGroupKey, $seenElementsByGroupKey, $sourceKeys, $element, $matches);
        }

        return $this->assembleRows($sourceKeysByGroupKey, $matchedRowsByGroupKey, $otherRows);
    }

    /**
     * Handles ONE document element that no named source claims (see `SOURCE_DEFINITIONS`) — labeled by
     * the product's own attribute values where one claims it, otherwise the generic "other" label.
     *
     * @param array<int, \SprykerCommunity\Yves\SearchDebugWidget\Resolver\TokenSourceRow> $otherRows
     * @param array<string, array<string, bool>> $seenElementsByGroupKey
     * @param array<string, array<int, string>> $attributeLabelByValue
     * @param string $element
     * @param array<int, array{token: string, startOffset: int, endOffset: int}> $matches
     */
    protected function addOtherRow(
        array &$otherRows,
        array &$seenElementsByGroupKey,
        array $attributeLabelByValue,
        string $element,
        array $matches,
    ): void {
        $attributeKeys = $attributeLabelByValue[$element] ?? [];
        $labelKeys = $attributeKeys !== [] ? $attributeKeys : [static::LABEL_KEY_OTHER];
        $groupKey = implode('|', $labelKeys);

        // Two raw document elements can be byte-identical (e.g. a concrete description that happens to
        // equal the abstract's) — one row per distinct (label, text) pair is enough; a second identical
        // row would only repeat information already shown.
        if (isset($seenElementsByGroupKey[$groupKey][$element])) {
            return;
        }

        $seenElementsByGroupKey[$groupKey][$element] = true;

        $otherRows[] = new TokenSourceRow(
            $labelKeys,
            $matches !== [],
            $attributeKeys === [],
            $this->tokenHighlighter->highlight($element, $matches),
            $matches !== [] ? $element : null,
            $matches,
        );
    }

    /**
     * Handles ONE document element a named source claims (see `SOURCE_DEFINITIONS`) — registers its
     * source-key group either way (even on a miss, so {@see assembleRows()} can still emit a "no match"
     * summary row for it), and adds a matched row only when the element actually contains the token.
     *
     * @param array<string, array<int, \SprykerCommunity\Yves\SearchDebugWidget\Resolver\TokenSourceRow>> $matchedRowsByGroupKey
     * @param array<string, array<int, string>> $sourceKeysByGroupKey
     * @param array<string, array<string, bool>> $seenElementsByGroupKey
     * @param array<int, string> $sourceKeys
     * @param string $element
     * @param array<int, array{token: string, startOffset: int, endOffset: int}> $matches
     */
    protected function registerNamedSourceElement(
        array &$matchedRowsByGroupKey,
        array &$sourceKeysByGroupKey,
        array &$seenElementsByGroupKey,
        array $sourceKeys,
        string $element,
        array $matches,
    ): void {
        // $sourceKeys is already in canonical order (built that way by ProductSourceMapBuilder::collectSourceKeysByValue()), so
        // the group key doubles as a stable identity for "this exact combination of sources".
        $groupKey = implode('|', $sourceKeys);
        $sourceKeysByGroupKey[$groupKey] = $sourceKeys;

        if ($matches === []) {
            return;
        }

        // Same de-duplication as addOtherRow(): a value appearing as two separate raw elements under the
        // SAME source attribution (e.g. abstract description == concrete description) would otherwise
        // render as two identical rows.
        if (isset($seenElementsByGroupKey[$groupKey][$element])) {
            return;
        }

        $seenElementsByGroupKey[$groupKey][$element] = true;

        $matchedRowsByGroupKey[$groupKey][] = new TokenSourceRow(
            array_map(fn (string $sourceKey): string => ProductSourceMapBuilder::SOURCE_DEFINITIONS[$sourceKey]['labelKey'], $sourceKeys),
            true,
            false,
            $this->tokenHighlighter->highlight($element, $matches),
            $element,
            $matches,
        );
    }

    /**
     * Reassembles the final, ordered row list: one entry per named-source group in canonical
     * {@see SOURCE_DEFINITIONS} order (its matched rows if it has any, otherwise ONE synthetic "no match"
     * summary row — a source with no matching element gets no row at all, handled by its absence from
     * $sourceKeysByGroupKey in the first place, see {@see registerNamedSourceElement()}), followed by every
     * "other" row.
     *
     * @param array<string, array<int, string>> $sourceKeysByGroupKey
     * @param array<string, array<int, \SprykerCommunity\Yves\SearchDebugWidget\Resolver\TokenSourceRow>> $matchedRowsByGroupKey
     * @param array<int, \SprykerCommunity\Yves\SearchDebugWidget\Resolver\TokenSourceRow> $otherRows
     *
     * @return array<int, \SprykerCommunity\Yves\SearchDebugWidget\Resolver\TokenSourceRow>
     */
    protected function assembleRows(array $sourceKeysByGroupKey, array $matchedRowsByGroupKey, array $otherRows): array
    {
        $canonicalOrder = array_flip(array_keys(ProductSourceMapBuilder::SOURCE_DEFINITIONS));
        uasort($sourceKeysByGroupKey, fn (array $a, array $b): int => $canonicalOrder[$a[0]] <=> $canonicalOrder[$b[0]]);

        $rows = [];
        foreach ($sourceKeysByGroupKey as $groupKey => $sourceKeys) {
            if (isset($matchedRowsByGroupKey[$groupKey])) {
                array_push($rows, ...$matchedRowsByGroupKey[$groupKey]);

                continue;
            }

            $rows[] = new TokenSourceRow(
                array_map(fn (string $sourceKey): string => ProductSourceMapBuilder::SOURCE_DEFINITIONS[$sourceKey]['labelKey'], $sourceKeys),
                false,
                false,
                null,
                null,
                [],
            );
        }

        return array_merge($rows, $otherRows);
    }

    /**
     * Collapses the tier-keyed source map into a plain `value => sourceKeys` lookup, so a value can still
     * be identified when the project indexes it into a different tier than {@see SOURCE_DEFINITIONS}
     * declares. Declared tiers stay the primary signal (they disambiguate values that legitimately mean
     * different things per tier); this is only consulted when the declared tier produced nothing.
     *
     * @param array<string, array<string, array<int, string>>> $sourceKeysByValue
     *
     * @return array<string, array<int, string>>
     */
    protected function flattenSourceKeysAcrossTiers(array $sourceKeysByValue): array
    {
        $flattened = [];

        foreach ($sourceKeysByValue as $sourceKeysByValueForTier) {
            foreach ($sourceKeysByValueForTier as $value => $sourceKeys) {
                $flattened[(string)$value] = array_values(array_unique(
                    array_merge($flattened[(string)$value] ?? [], $sourceKeys),
                ));
            }
        }

        return $flattened;
    }

    /**
     * @param string $element
     * @param string $token
     *
     * @return array<array{token: string, startOffset: int, endOffset: int}>
     */
    protected function findTokenMatches(string $element, string $token): array
    {
        $this->tokenOffsetsCache[$element] ??= $this->searchDebugClient->getTextTokenOffsets($element);

        return array_values(array_filter(
            $this->tokenOffsetsCache[$element],
            fn (array $tokenOffset): bool => $tokenOffset['token'] === $token,
        ));
    }
}
