<?php

/**
 * This file is part of the spryker-community/search-debug package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Client\SearchDebug\Explanation;

class ExplanationParser implements ExplanationParserInterface
{
    /**
     * Matches Lucene term-weight explanation descriptions, e.g.
     * "weight(full-text-boosted:hamm in 42) [PerFieldSimilarity], result of:".
     *
     * Public: also referenced by {@see CrossFieldsSynonymMatcher}, which recognizes term-weight leaves
     * while resolving a DIFFERENT explain-tree shape (see that class's own doc).
     *
     * @var string
     */
    public const TERM_WEIGHT_PATTERN = '/^weight\((?<field>[^:\s()]+):(?<term>.+?) in \d+\)/';

    /**
     * Matches a Lucene SYNONYM weight node — one query position expanded into several alternative terms
     * by a synonym filter, e.g. "weight(Synonym(full-text:button full-text:switch) in 12803)". Confirmed
     * live: this is a SINGLE node with ONE combined value for the whole group, not one node per term — a
     * synonym filter genuinely cannot be scored as independent per-term contributions, since Lucene
     * matched "either of these, at this one position", not each term separately. `terms` captures the
     * raw space-separated `field:term` pairs, however many the group has (a synonym rule can list any
     * number of equivalent words) — parsed by {@see TermWeightAccumulator::addSynonym()}.
     *
     * @var string
     */
    protected const SYNONYM_WEIGHT_PATTERN = '/^weight\(Synonym\((?<terms>.+?)\) in \d+\)/';

    /**
     * Every Lucene weight node starts with this, including shapes neither {@see TERM_WEIGHT_PATTERN} nor
     * {@see SYNONYM_WEIGHT_PATTERN} recognizes.
     *
     * @var string
     */
    protected const WEIGHT_NODE_PREFIX = 'weight(';

    /**
     * The combined-term display key joins a synonym group's terms with this, e.g. "button, switch" — the
     * SAME separator {@see splitByQueryTokens()} splits back on to check membership against the user's
     * actual query tokens. Also matches the separator {@see \SprykerCommunity\Client\SearchDebug\Analyzer\ComponentDefinitionFormatter}
     * already uses to join a config list preview, for a consistent "these are a group" visual convention
     * across the debug tool.
     *
     * Public: also referenced by {@see CrossFieldsSynonymMatcher}, which builds the same kind of combined
     * display key for the shape it recognizes.
     *
     * @var string
     */
    final public const SYNONYM_TERM_SEPARATOR = ', ';

    /**
     * Matches a `function_score` boost-function leaf's own description — the documented Lucene/ES
     * phrasing for `field_value_factor`, decay functions (`gauss`/`exp`/`linear`), and `script_score`.
     * Confirmed live against a real `script_score` function_score (the search-ranking business-signal
     * query): its node matches this pattern but arrives WITH details, so it is handled by the dedicated
     * script-score branch in {@see walkNode()} instead; this leaf pattern remains the net for the other
     * function types and detail-less shapes. If real explain output ever uses different wording, the
     * worst case is these nodes fall through to the generic {@see KEY_OTHER_CONTRIBUTIONS} bucket
     * instead of {@see KEY_SCORE_FUNCTIONS} — never dropped.
     *
     * @var string
     */
    protected const SCORE_FUNCTION_PATTERN = '/^(field value function|function score|script score function|gauss|exp|linear)\b/i';

    /**
     * A `script score function` node as produced by a real function_score query (confirmed live against
     * the search-ranking business-signal query, ES 7 / boost_mode "replace"):
     *
     *   sum of: <- V (final _score)
     *   ├─ min of: <- V
     *   │ ├─ script score function, ...: <- V, THIS node
     *   │ │ └─ _score: <- S, the wrapped query's own relevance score
     *   │ │ └─ (the original query tree) <- the familiar term-weight breakdown
     *   │ └─ maxBoost <- 3.4028235E38 (float-max sentinel, pure noise)
     *   └─ match on required clause (0) <- filtered by the zero check
     *
     * @var string
     */
    protected const SCRIPT_SCORE_NODE_PREFIX = 'script score function';

    /**
     * The child of a script-score node carrying the WRAPPED query's own relevance score — the value the
     * painless script saw as `_score`. Matched by prefix: ES renders it as "_score: " (trailing space).
     *
     * @var string
     */
    protected const QUERY_SCORE_NODE_PREFIX = '_score';

    /**
     * A function_score explain always caps the function result with a `min of: [function, maxBoost]`
     * node whose maxBoost leaf is FLT_MAX (3.4028235E38) unless a max_boost was configured — a sentinel,
     * not a score contribution; surfacing it as one would show a nonsensical gigantic number.
     *
     * @var string
     */
    protected const MAX_BOOST_DESCRIPTION = 'maxBoost';

    /**
     * Matches the description of a node that combines its children by taking the MAXIMUM (Lucene
     * `DisjunctionMaxQuery`, i.e. a `dis_max`/`best_fields` multi_match) — e.g. "max of:" or
     * "max plus 0.1 times others of:" for a non-zero tie_breaker.
     *
     * Public: also referenced by {@see CrossFieldsSynonymMatcher}, which matches this same combiner shape
     * while resolving a different explain-tree case.
     *
     * @var string
     */
    public const COMBINE_PATTERN_MAX = '/^max\b.*of:$/i';

    /**
     * Matches the description of a node that combines its children by SUMMING them (Lucene `BooleanQuery`
     * "should" clauses, i.e. a `most_fields`/`cross_fields` multi_match without full field blending) —
     * e.g. "sum of:".
     *
     * @var string
     */
    protected const COMBINE_PATTERN_SUM = '/^sum\b.*of:$/i';

    /**
     * Public: also referenced by {@see TermWeightAccumulator}, which owns the actual per-field combine
     * logic these two modes drive.
     *
     * @var string
     */
    public const COMBINE_MODE_MAX = 'max';

    /**
     * @var string
     */
    public const COMBINE_MODE_SUM = 'sum';

    /**
     * @var string
     */
    public const KEY_MATCHED_TOKENS = 'matchedTokens';

    /**
     * @var string
     */
    public const KEY_OTHER_CONTRIBUTIONS = 'otherContributions';

    /**
     * @var string
     */
    public const KEY_SCORE_FUNCTIONS = 'scoreFunctions';

    /**
     * The query's own pure text-relevance score (float) — the matched-token contributions always add up
     * against THIS number, not necessarily the final `_score`. Two sources, depending on the tree shape:
     * - When a function_score script wrapped the query (see {@see tryHandleScriptScoreNode()}), this is
     *   the wrapped query's own relevance BEFORE the score function ran — the `_score:` child's value.
     * - Otherwise, {@see parse()} falls back to the tree's own top-level `value` — for a plain (non-
     *   function_score) query, Lucene's `_explanation.value` for the whole tree already IS the final
     *   `_score`, and since nothing else touched it, that final score is by definition the pure text
     *   relevance too. This fallback is what lets a caller (e.g. search-ranking's overlay section for the
     *   normalized text signal) show a meaningful text-relevance number REGARDLESS of whether business
     *   signals happen to be active for this query — the two are logically independent (one query token
     *   can legitimately have a text score with zero configured/active business metrics), so this key
     *   must never be held hostage to "did a function_score wrapper happen to run".
     *
     * In practice this is only ever null if {@see parse()} itself is never called — Elasticsearch always
     * reports SOME numeric `value` for an explain tree that exists at all.
     *
     * @var string
     */
    public const KEY_QUERY_SCORE = 'queryScore';

    /**
     * @param \SprykerCommunity\Client\SearchDebug\Explanation\CrossFieldsSynonymMatcher $crossFieldsSynonymMatcher
     * @param \SprykerCommunity\Client\SearchDebug\Explanation\Bm25BreakdownExtractor $bm25BreakdownExtractor
     */
    public function __construct(protected CrossFieldsSynonymMatcher $crossFieldsSynonymMatcher, protected Bm25BreakdownExtractor $bm25BreakdownExtractor)
    {
    }

    /**
     * {@inheritDoc}
     *
     * @param array<string, mixed> $explanation
     * @param array<string> $queryTokens
     *
     * @return array<string, mixed>
     */
    public function parse(array $explanation, array $queryTokens): array
    {
        // A fresh accumulator per call, never shared across calls — see TermWeightAccumulator's own doc
        // for why (one search-result page parses several hits' explain trees with the same parser).
        // Deliberately not Factory-created: unlike the constructor-injected collaborators above (which are
        // assembled once and reused for this parser's whole lifetime), this is throwaway per-call state
        // with no dependencies of its own — the same category as a Transfer, Context, or Collection object,
        // which Spryker core itself `new`s inline throughout (e.g. Oms\OrderStateMachine's
        // ConditionCollection/CommandCollection, Discount\DecisionRuleProvider's DecisionRuleContext).
        $termWeightAccumulator = new TermWeightAccumulator();
        $otherContributions = [];
        $scoreFunctions = [];
        $queryScore = null;

        $this->walkNode($explanation, $termWeightAccumulator, $otherContributions, $scoreFunctions, $queryScore);

        // No script-score node means no function_score wrapper ran — but the tree's own top-level value
        // is still the query's final `_score`, and with nothing else touching it, that IS the pure text
        // relevance. See KEY_QUERY_SCORE's own docblock for why this must not depend on business signals.
        $queryScore ??= (float)($explanation['value'] ?? 0.0);

        $result = $this->splitByQueryTokens($termWeightAccumulator->getTerms(), $otherContributions, $queryTokens);
        $result[static::KEY_SCORE_FUNCTIONS] = $scoreFunctions;
        $result[static::KEY_QUERY_SCORE] = $queryScore;

        return $result;
    }

    /**
     * Walks the recursive `_explanation` tree by node shape. FIRST-MATCH-WINS: the `try*()` calls below run
     * in a fixed order, and a node shaped like more than one of them at once is resolved by whichever check
     * runs first — this order is the single most important fact about this method (see
     * {@see \SprykerCommunityTest\Client\SearchDebug\Explanation\ExplanationParserTest} for a node
     * engineered to match two shapes at once, asserting which one wins):
     * - A zero-valued node contributes nothing (see {@see tryDropZeroValue()}).
     * - The `function_score` float-max sentinel is noise, not a score (see {@see tryDropMaxBoostSentinel()}).
     * - A `script score function` node is handled, and walked into, specially (see
     *   {@see tryHandleScriptScoreNode()}).
     * - A weight node attributable to one `field:term` pair (or a synonym group) is collected as a term
     *   contribution; any other weight node is kept verbatim (see {@see tryHandleWeightNode()}).
     * - A node with children is either the ONE specific cross_fields-synonym shape
     *   {@see CrossFieldsSynonymMatcher} recognizes, or an ordinary parent to recurse into, updating
     *   $combineMode along the way (see {@see tryHandleParentNode()}).
     * - An empty description carries nothing (see {@see tryDropEmptyDescription()}).
     * - A `function_score` boost-function leaf is collected separately from other contributions (see
     *   {@see tryHandleScoreFunctionLeaf()}).
     * - Anything else is kept verbatim, so an unknown query shape degrades gracefully instead of being
     *   dropped.
     *
     * @param array<string, mixed> $node
     * @param \SprykerCommunity\Client\SearchDebug\Explanation\TermWeightAccumulator $termWeightAccumulator
     * @param array<int, array<string, mixed>> $otherContributions
     * @param array<int, array<string, mixed>> $scoreFunctions
     * @param float|null $queryScore
     * @param string|null $combineMode
     */
    protected function walkNode(
        array $node,
        TermWeightAccumulator $termWeightAccumulator,
        array &$otherContributions,
        array &$scoreFunctions,
        ?float &$queryScore,
        ?string $combineMode = null,
    ): void {
        $value = (float)($node['value'] ?? 0.0);

        if ($this->tryDropZeroValue($value)) {
            return;
        }

        $description = (string)($node['description'] ?? '');

        if ($this->tryDropMaxBoostSentinel($description)) {
            return;
        }

        if ($this->tryHandleScriptScoreNode($node, $value, $description, $termWeightAccumulator, $otherContributions, $scoreFunctions, $queryScore)) {
            return;
        }

        if ($this->tryHandleWeightNode($node, $value, $description, $termWeightAccumulator, $otherContributions, $combineMode)) {
            return;
        }

        if ($this->tryHandleParentNode($node, $value, $description, $termWeightAccumulator, $otherContributions, $scoreFunctions, $queryScore, $combineMode)) {
            return;
        }

        if ($this->tryDropEmptyDescription($description)) {
            return;
        }

        if ($this->tryHandleScoreFunctionLeaf($description, $value, $scoreFunctions)) {
            return;
        }

        $otherContributions[] = [
            'description' => $description,
            'value' => $value,
        ];
    }

    /**
     * A zero-valued node contributes nothing to its parent's combined score, even when its own children
     * report non-zero numbers deeper down. Filter-context clauses (e.g. active facet filters) are a common
     * case: Lucene explains them internally for transparency but excludes them from scoring — e.g. "match
     * on required clause, product of: # clause (0) * *:* (1)" reports 0 at every ancestor level despite the
     * literal "1" leaf inside it. Stopping at the first zero avoids surfacing that inner "1" as a fake
     * contribution.
     *
     * @param float $value
     */
    protected function tryDropZeroValue(float $value): bool
    {
        return $value === 0.0;
    }

    /**
     * @param string $description
     */
    protected function tryDropMaxBoostSentinel(string $description): bool
    {
        return $description === static::MAX_BOOST_DESCRIPTION;
    }

    /**
     * @param array<string, mixed> $node
     * @param float $value
     * @param string $description
     * @param \SprykerCommunity\Client\SearchDebug\Explanation\TermWeightAccumulator $termWeightAccumulator
     * @param array<int, array<string, mixed>> $otherContributions
     * @param array<int, array<string, mixed>> $scoreFunctions
     * @param float|null $queryScore
     */
    protected function tryHandleScriptScoreNode(
        array $node,
        float $value,
        string $description,
        TermWeightAccumulator $termWeightAccumulator,
        array &$otherContributions,
        array &$scoreFunctions,
        ?float &$queryScore,
    ): bool {
        if (!str_starts_with($description, static::SCRIPT_SCORE_NODE_PREFIX)) {
            return false;
        }

        $this->addScriptScoreNode($node, $value, $description, $termWeightAccumulator, $otherContributions, $scoreFunctions, $queryScore);

        return true;
    }

    /**
     * @param array<string, mixed> $node
     * @param float $value
     * @param string $description
     * @param \SprykerCommunity\Client\SearchDebug\Explanation\TermWeightAccumulator $termWeightAccumulator
     * @param array<int, array<string, mixed>> $otherContributions
     * @param string|null $combineMode
     */
    protected function tryHandleWeightNode(
        array $node,
        float $value,
        string $description,
        TermWeightAccumulator $termWeightAccumulator,
        array &$otherContributions,
        ?string $combineMode,
    ): bool {
        if (!str_starts_with($description, static::WEIGHT_NODE_PREFIX)) {
            return false;
        }

        if (preg_match(static::TERM_WEIGHT_PATTERN, $description, $matches)) {
            $termWeightAccumulator->addTerm($matches['term'], $matches['field'], $value, $combineMode ?? static::COMBINE_MODE_MAX, $this->bm25BreakdownExtractor->extract($node));

            return true;
        }

        if (preg_match(static::SYNONYM_WEIGHT_PATTERN, $description, $matches)) {
            $termWeightAccumulator->addSynonym($matches['terms'], $value, $combineMode ?? static::COMBINE_MODE_MAX, $this->bm25BreakdownExtractor->extract($node));

            return true;
        }

        $otherContributions[] = [
            'description' => $description,
            'value' => $value,
        ];

        return true;
    }

    /**
     * @param array<string, mixed> $node
     * @param float $value
     * @param string $description
     * @param \SprykerCommunity\Client\SearchDebug\Explanation\TermWeightAccumulator $termWeightAccumulator
     * @param array<int, array<string, mixed>> $otherContributions
     * @param array<int, array<string, mixed>> $scoreFunctions
     * @param float|null $queryScore
     * @param string|null $combineMode
     */
    protected function tryHandleParentNode(
        array $node,
        float $value,
        string $description,
        TermWeightAccumulator $termWeightAccumulator,
        array &$otherContributions,
        array &$scoreFunctions,
        ?float &$queryScore,
        ?string $combineMode,
    ): bool {
        $details = $node['details'] ?? [];

        if ($details === []) {
            return false;
        }

        $synonymMatch = $this->crossFieldsSynonymMatcher->match($description, $details, $value);

        if ($synonymMatch !== null) {
            $breakdown = $synonymMatch['winningNode'] !== null ? $this->bm25BreakdownExtractor->extract($synonymMatch['winningNode']) : null;
            $termWeightAccumulator->addTerm($synonymMatch['term'], $synonymMatch['field'], $value, static::COMBINE_MODE_MAX, $breakdown);

            return true;
        }

        // A node's OWN description is only trusted as a combine-mode signal when it actually combines
        // more than one child — summing or maxing a SINGLE value produces that same value either way, so
        // a single-child node reveals nothing about how things combine; it must pass the INHERITED mode
        // through unchanged instead of overriding it with its own (structurally present but semantically
        // meaningless here) "sum of:"/"max of:" wording.
        //
        // Confirmed live: a synonym-expanded term wraps EACH field's weight in its own single-child "sum
        // of:" node (`weight(Synonym(...) in N)` is its only child) — plain, non-synonym term matches have
        // no such wrapper at all. Without this check, that inner "sum of:" incorrectly overrode the REAL
        // governing combiner (an ancestor "max of:" node combining the two fields' weights via dis_max),
        // making a synonym group's per-field weights get SUMMED instead of MAX'd — inflating its
        // matched-token total past the document's actual `_score`.
        $childCombineMode = count($details) > 1
            ? ($this->detectCombineMode($description) ?? $combineMode)
            : $combineMode;

        foreach ($details as $childNode) {
            $this->walkNode($childNode, $termWeightAccumulator, $otherContributions, $scoreFunctions, $queryScore, $childCombineMode);
        }

        return true;
    }

    /**
     * @param string $description
     */
    protected function tryDropEmptyDescription(string $description): bool
    {
        return $description === '';
    }

    /**
     * @param string $description
     * @param float $value
     * @param array<int, array<string, mixed>> $scoreFunctions
     */
    protected function tryHandleScoreFunctionLeaf(string $description, float $value, array &$scoreFunctions): bool
    {
        if (preg_match(static::SCORE_FUNCTION_PATTERN, $description) !== 1) {
            return false;
        }

        $scoreFunctions[] = [
            'description' => $description,
            'value' => $value,
        ];

        return true;
    }

    /**
     * Handles a `script score function` node WITH children — the shape a real function_score script
     * produces (see {@see SCRIPT_SCORE_NODE_PREFIX} for the confirmed tree). Three things happen:
     * - The node itself is recorded as a score function (its value IS the function's result).
     * - Its `_score:` child's value is captured as {@see KEY_QUERY_SCORE} — the wrapped query's own
     *   relevance score, which the matched-token breakdown adds up against.
     * - The walk continues INTO the `_score:` child, so the familiar term-weight breakdown of the
     *   wrapped query is parsed exactly as if no function_score existed.
     * Children other than `_score:` (none observed live; defensive) get the normal walk too.
     *
     * @param array<string, mixed> $node
     * @param float $value
     * @param string $description
     * @param \SprykerCommunity\Client\SearchDebug\Explanation\TermWeightAccumulator $termWeightAccumulator
     * @param array<int, array<string, mixed>> $otherContributions
     * @param array<int, array<string, mixed>> $scoreFunctions
     * @param float|null $queryScore
     */
    protected function addScriptScoreNode(
        array $node,
        float $value,
        string $description,
        TermWeightAccumulator $termWeightAccumulator,
        array &$otherContributions,
        array &$scoreFunctions,
        ?float &$queryScore,
    ): void {
        $scoreFunctions[] = [
            'description' => $description,
            'value' => $value,
        ];

        foreach ($node['details'] ?? [] as $childNode) {
            $childDescription = (string)($childNode['description'] ?? '');

            if ($queryScore === null && str_starts_with($childDescription, static::QUERY_SCORE_NODE_PREFIX)) {
                $queryScore = (float)($childNode['value'] ?? 0.0);
            }

            $this->walkNode($childNode, $termWeightAccumulator, $otherContributions, $scoreFunctions, $queryScore);
        }
    }

    /**
     * @param string $description
     *
     * @return string|null One of {@see COMBINE_MODE_MAX}/{@see COMBINE_MODE_SUM}, or null when
     *   $description doesn't match a known combiner shape (the caller then keeps the inherited mode).
     */
    protected function detectCombineMode(string $description): ?string
    {
        if (preg_match(static::COMBINE_PATTERN_SUM, $description) === 1) {
            return static::COMBINE_MODE_SUM;
        }

        if (preg_match(static::COMBINE_PATTERN_MAX, $description) === 1) {
            return static::COMBINE_MODE_MAX;
        }

        return null;
    }

    /**
     * Splits collected term weights into the user's actual query tokens and everything else.
     *
     * The explain tree contains `weight(field:term)` nodes that are not part of the search string — the
     * internal `type:product_abstract` filter clause every catalog query includes is structurally
     * indistinguishable from a real term match. Those belong under "other contributions": dropping them
     * would show a score without a visible source, and keeping them under "matched tokens" would claim
     * the user searched for something they did not.
     *
     * `array_flip()` builds the lookup set deliberately: PHP coerces numeric-string array keys to int
     * (so a term like "8845" is stored as int 8845 by {@see TermWeightAccumulator::addTerm()}), and
     * `isset()` applies the same coercion to the lookup key — whereas a strict `in_array()` against the
     * string token list would not match, silently demoting every numeric query token to an "other
     * contribution".
     *
     * A combined synonym-group key (e.g. "button, switch", from {@see TermWeightAccumulator::addSynonym()})
     * is never itself one of the user's query tokens — it's split back into its constituent terms and
     * counted as matched when ANY of them is a real query token (they all originate from the SAME
     * synonym expansion of the query, so in practice either all of a group's terms are query tokens or
     * none are).
     *
     * @param array<string, array<string, mixed>> $terms
     * @param array<int, array<string, mixed>> $otherContributions
     * @param array<string> $queryTokens
     *
     * @return array<string, mixed>
     */
    protected function splitByQueryTokens(array $terms, array $otherContributions, array $queryTokens): array
    {
        $queryTokenSet = array_flip($queryTokens);
        $matchedTokens = [];

        foreach ($terms as $term => $termInfo) {
            /** @var non-empty-string $separator */
            $separator = static::SYNONYM_TERM_SEPARATOR;

            $termNames = str_contains((string)$term, $separator)
                ? explode($separator, (string)$term)
                : [(string)$term];

            $isMatchedQueryToken = false;
            foreach ($termNames as $termName) {
                if (isset($queryTokenSet[$termName])) {
                    $isMatchedQueryToken = true;

                    break;
                }
            }

            if ($isMatchedQueryToken) {
                // fieldWeights is accumulation state for TermWeightAccumulator::addTerm(), not part of the output
                // contract: only the winning field contributes to the score (dis_max), so only
                // `field`/`total` carry displayable information.
                $matchedTokens[$term] = [
                    'total' => $termInfo['total'],
                    'field' => $termInfo['field'],
                ];

                // `breakdown` (the winning field's own boost/idf/tf numbers) is only ever ADDED, never
                // set to null — a different Similarity module, or a synonym shape the extractor hasn't
                // been confirmed against live, simply leaves this key absent rather than present-but-null,
                // so callers can keep using a plain `is not empty`/`isset` check either way.
                $breakdown = $termInfo['fieldBreakdowns'][$termInfo['field']] ?? null;

                if ($breakdown !== null) {
                    $matchedTokens[$term]['breakdown'] = $breakdown;
                }

                continue;
            }

            $otherContributions[] = [
                'description' => sprintf('%s:%s', $termInfo['field'], $term),
                'value' => $termInfo['total'],
            ];
        }

        return [
            static::KEY_MATCHED_TOKENS => $matchedTokens,
            static::KEY_OTHER_CONTRIBUTIONS => $otherContributions,
        ];
    }
}
