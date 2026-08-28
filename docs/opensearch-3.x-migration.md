# Migrating to OpenSearch 3.x

Verified live end-to-end: a Spryker demoshop upgraded from **OpenSearch 1.3.4 to 3.5.0** (Lucene 10.3.2),
full re-export/reindex, the `_explanation` tree and `_analyze` output re-parsed against the live 3.5
cluster, `search-debug:check-installation` re-run.

**This package needs no code change for OpenSearch 3.x.**

## Why the parser survives the jump

`ExplanationParser` reads the `_explanation` tree Lucene produces for the ranking query. On OpenSearch 3.5
that tree keeps the exact structure the parser is built around:

```
sum of:
  function score, product of: …
  match on required clause, product of: …   (BM25 boost / idf / tf breakdown)
```

— the same `sum of:` → `function score` / `match on required clause` shape as on 1.3.4 and 2.11, re-parsed
live on the upgraded demoshop with no parser change. Lucene wording *does* drift between generations (e.g.
`dl, length of field (approximate)` in 8.10 became `dl, length of field` in 9.7, and 10.3 keeps that
shorter form); the parser already reads those nodes by prefix, not exact string, which is the degradation
posture that carried it across all three Lucene generations. The `_analyze` output this package renders in
its word-level analysis page uses only tokenizer/filter primitives shared across both engine lineages and
is likewise unchanged.

## Capability delta 1.3.x → 3.5 (engine facts, not this package's surface)

Probed directly against a live OpenSearch 1.3.14 and a live 3.5.0:

| capability | 1.3.x | 3.5 |
|---|---|---|
| `function_score` + painless, `_explain`, `_analyze`, `_validate/query` | ✅ | ✅ |
| `pinned` query | ❌ | ❌ (Elastic-licensed, never in OpenSearch) |
| **`hybrid` query** | ❌ | ✅ (neural-search plugin; OpenSearch ≥ 2.10) |
| **`_search/pipeline`** | ❌ | ✅ (OpenSearch ≥ 2.8) |
| `_plugins/_ml` (ML Commons) | ✅ | ✅ — the endpoint is present on both; 3.x adds in-cluster model serving on top |
| `_plugins/_ltr` (Learning To Rank) | ❌ | ❌ (third-party, in neither stock image) |

None of these are used by this package.

## The one upgrade-time trap (not this package's code)

OpenSearch 3.x's bundled neural-search `SemanticMappingTransformer` runs on **every index create** and
rejects `"some-field": { "type": "object", "properties": {} }` with
`class java.util.ArrayList cannot be cast to class java.util.Map` — PHP's `json_decode` turns the empty
`{}` into `[]`, which Spryker then PUTs. Spryker Cloud Commerce fixed this in five core packages
(SC-25160); a project schema override that makes `properties` non-empty works around any third-party schema
still carrying it. `spryker-community/search-ranking`'s migration guide has the fuller write-up.
