# Compiler Performance

++PHP includes a repeatable, offline benchmark for compiler reuse. These measurements describe one development machine and one compiler checkout; they are informative evidence, not universal guarantees or CI timing thresholds. They are not claims about native compilation or runtime PHP performance.

## Methodology

`tools/benchmark-compiler.php` generates deterministic mixed projects, runs ten scenarios in one process, removes every temporary project, and reports the median of the requested iterations. The full certification run on 2 September 2026 used:

~~~bash
php -d memory_limit=768M tools/benchmark-compiler.php \
  --profile=full \
  --iterations=3 \
  --format=json \
  --output=/private/tmp/ppphp-benchmark.json \
  --markdown-output=/private/tmp/ppphp-benchmark.md
~~~

The JSON and Markdown were rendered from the same measured report. Each scenario records wall-clock duration, PHP allocated peak memory, output/cache bytes, cache hits and misses, files normalized/tokenized/reparsed/lowered, semantic analyses, and PHPStan/lint process launches. The generated fixtures contain one feature-heavy ++PHP source that consumes a deterministic Composer dependency declaration plus independent small functions; every fifth module is ordinary PHP.

Environment:

- Mac mini (Macmini9,1), Apple M1, 8 cores, 8 GB memory, arm64;
- macOS 26.6.2 (25G83);
- PHP 8.5.6 with a 768 MiB benchmark limit;
- PHPStan 2.2.9, PHP-Parser 5.8.0, and Symfony Process 8.1.5; and
- compiler public version `dev-2026.3.1` at the measured development checkout.

## Fixture Sizes

| Fixture | Source files | Source bytes | Built files | Built bytes |
| --- | ---: | ---: | ---: | ---: |
| Small | 6 | 783 | 13 | 15,832 |
| Medium | 40 | 2,683 | 81 | 60,852 |
| Large | 240 | 14,023 | 481 | 324,032 |

Built counts include PHP artifacts, source maps, and the manifest. Output byte values shown here are from the cold pathless build; declaration-edit output differs by only the edited declaration bytes.

## Median Results

| Scenario | Small ms | Medium ms | Large ms |
| --- | ---: | ---: | ---: |
| Cold compiler-core check | 820.455 | 851.912 | 1,069.799 |
| Exact warm compiler-core check | 0.193 | 0.385 | 1.606 |
| Cold full check | 2,463.221 | 2,552.764 | 2,898.382 |
| Exact warm full check | 1.573 | 2.723 | 8.945 |
| Cold pathless build | 2,811.345 | 4,869.171 | 20,656.286 |
| Exact warm pathless build | 2.258 | 8.412 | 73.654 |
| Implementation-body edit | 2,343.254 | 4,370.603 | 19,751.293 |
| Public-declaration edit | 2,340.237 | 4,398.700 | 19,973.459 |
| Focused-file check | 2,051.653 | 2,233.260 | 4,281.026 |
| Focused-file build | 1,663.284 | 1,799.910 | 4,066.072 |

The maximum recorded allocated peak was 270,532,608 bytes. PHP retains allocated arenas in this single-process harness, so warm-scenario peaks reflect the process high-water allocation rather than the marginal memory required by the warm operation.

Cache size after the cold build was 3,208,459 bytes for small, 3,383,789 bytes for medium, and 4,413,469 bytes for large. After both localized edits it was 3,239,231, 3,542,329, and 5,323,449 bytes respectively. The production cache is independently bounded by size and record/blob counts; these fixture values are observations, not configured targets.

## Structurally Avoided Work

Exact warm compiler-core and full checks tokenized, normalized, and reparsed zero files, ran zero semantic analyses, and launched zero PHPStan processes at every size. Exact warm builds additionally lowered zero files, launched zero lint processes, and retained the existing valid output without a candidate commit.

For a body-only edit, the public declaration fingerprint remained unchanged. The compiler reused unchanged body-free declaration units and 5 of 6, 39 of 40, and 239 of 240 safe artifact units, lowering only the edited file. A public-declaration edit invalidated the project artifact boundary and lowered all 6, 40, or 240 files. These gates use counters and identities rather than interpreting elapsed time as proof.

## Known Remaining Costs

A localized body edit still performs full selected parsing and semantic analysis, prepares supplemental context, launches PHPStan, and lints the complete candidate tree, although unchanged body-free declaration representations and production artifacts are reused. Focused operations also reconstruct complete semantic declaration context. The cache deliberately prefers these conservative costs to persisting a partial semantic model or inventing an unsound dependency graph. Future work may split more reusable frontend products only with complete versioned representations and invalidation evidence.

Run `composer verify:benchmark-harness` for the bounded small-fixture structural smoke check. The full three-size benchmark remains a manual development command and does not run in ordinary CI.

## Unsaved-Buffer Worker

`editor:diagnostics --server` retains verified platform declarations and immutable
derived metadata between requests. It does not reuse old diagnostic results or
project semantic models. Project files, configuration, dependencies, and overlays
are reloaded for each request; platform resource hashes are checked before reuse.
The worker also rechecks compiler build identity before analysis and retires if
its installation has changed, including same-version compiler source changes.
See [the worker contract](editor-protocol.md#retained-worker-transport) for
cancellation, recycling, and single-shot fallback requirements.

Run the transport benchmark against a valid project with at most 32 source files:

~~~bash
php tools/benchmark-editor-diagnostics.php \
  --project=../ppphp-examples/ppphp-mvp-showcase \
  --document=app/Application/DemoRunner.ppphp --iterations=20
~~~

The benchmark sends all project sources as buffers, measures the first valid
request, then alternates a syntax error and the repaired original buffer. It
verifies the expected diagnostic outcomes and reports raw samples and nearest-rank
percentiles. It writes no project files and excludes LSP debounce and UI rendering.

On the development Mac mini with PHP 8.5.6, the ten-file showcase measured:

| Transition | Median ms | p95 ms |
| --- | ---: | ---: |
| Syntax error, 20 samples | 46.5 | 51.4 |
| Repaired buffer, 20 samples | 174.7 | 186.4 |

Worker startup took 139.2 ms and the first valid analysis took a further 784.5 ms
(one cold sample). These measurements are not a universal 200 ms guarantee: larger
projects, cold starts, recycling, editor scheduling, and machine load add costs.
An additional five-cycle run on the same machine using the supported PHP 8.4 host
measured repaired buffers at 218.4 ms median / 226.8 ms p95, with a 1,117.2 ms first
analysis, further illustrating host-dependent costs.
Integrations must measure actual valid → error → repaired editor transitions before
claiming end-to-end latency. There is no CI wall-clock timing threshold.
