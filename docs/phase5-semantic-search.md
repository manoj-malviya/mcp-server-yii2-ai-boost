# Phase 5: Semantic Search — Design Options

Replacing the current grep-based `search_guidelines` tool with ranked, semantic-aware search. The goal is to match Laravel Boost's 17k+ entry documentation API without requiring a paid embeddings service.

---

## Option 1: SQLite FTS5 (Local Only)

**How it works:**
- SQLite's Full-Text Search (FTS5) is built into PHP's SQLite extension — no external dependencies
- At `boost/install` or `boost/update`, index all local guideline files into an FTS5 virtual table
- Queries use BM25 ranking for relevance scoring
- Entirely local, zero network calls at query time

**Content source:** Only the bundled `.ai/guidelines/` files (currently ~36KB)

**Pros:**
- Zero external dependencies — works offline
- Fast — FTS5 queries are sub-millisecond
- BM25 ranking is a massive upgrade over grep
- Simple to implement

**Cons:**
- Limited to content we ship with the package
- Content doesn't grow unless we manually add more guideline files
- No access to broader Yii2 ecosystem knowledge

**Effort:** Low

---

## Option 2: GitHub API as Search Backend

**How it works:**
- Use GitHub's REST Search API to search across `yiisoft/*` repositories at query time
- `GET https://api.github.com/search/code?q={query}+org:yiisoft` for code search
- `GET https://api.github.com/search/issues?q={query}+repo:yiisoft/yii2` for discussions/issues
- Can also fetch raw file content: `https://raw.githubusercontent.com/yiisoft/yii2/master/docs/guide/{file}.md`

**Content source:** Live Yii2 repos — framework source, guide, extensions, issues

**Rate limits:**
- Unauthenticated: 10 requests/minute
- With GitHub token: 30 requests/minute
- Search API: 10 results per page, paginated

**Pros:**
- Access to the entire Yii2 ecosystem (source code, docs, issues)
- Always up to date — searches live repos
- No local storage needed

**Cons:**
- Requires network access at query time
- Rate limits could be hit during heavy AI usage
- Results are code-oriented, not guide-oriented — noisy
- Latency per query (network round-trip)
- May require GitHub token for reasonable rate limits

**Effort:** Medium

---

## Option 3: Hybrid — GitHub Content + SQLite FTS5 Search (Recommended)

**How it works:**
- `boost/update` fetches the Yii2 definitive guide from GitHub raw content and caches locally
- Guide lives at: `https://github.com/yiisoft/yii2/tree/master/docs/guide` (~50 markdown files)
- Combine with our bundled guidelines for a comprehensive knowledge base
- Index everything into a local SQLite FTS5 table
- Search is local and ranked — no network calls at query time

**Content pipeline (`boost/update`):**
1. Fetch guide index from GitHub (list of .md files in docs/guide/)
2. Download each markdown file via raw.githubusercontent.com
3. Parse into sections (split on headings)
4. Insert into SQLite FTS5 table with metadata (source, category, title)
5. Also index local `.ai/guidelines/` files
6. Store the FTS database in `@runtime/` or `.ai/`

**Search flow (`semantic_search` tool):**
1. Query FTS5 table with BM25 ranking
2. Return top N results with title, snippet, source, relevance score
3. Optionally filter by category (database, security, views, etc.)

**Content sources:**
- Yii2 definitive guide (~50 files covering all framework topics)
- Bundled `.ai/guidelines/` files (current 36KB)
- Could later add: extension docs, API reference, cookbook entries

**Pros:**
- Large knowledge base without paid API (guide alone is ~200KB of content)
- BM25 ranked search — far better than grep
- Fast local queries, no network at search time
- Content refreshed on demand via `boost/update`
- Natural fit with existing `boost/update` command
- Extensible — easy to add more content sources later

**Cons:**
- Initial setup requires network (one-time download)
- Content may lag behind latest Yii2 changes (until next `boost/update`)
- More complex implementation than Option 1

**Effort:** Medium-High

---

## Comparison

| Aspect | Option 1: FTS5 Only | Option 2: GitHub API | Option 3: Hybrid |
|--------|---------------------|---------------------|-----------------|
| Content size | ~36KB (bundled) | Unlimited (live) | ~250KB+ (cached) |
| Network at query time | No | Yes | No |
| Ranking quality | BM25 | GitHub relevance | BM25 |
| Offline support | Yes | No | Yes (after first update) |
| Rate limits | None | 10-30 req/min | None at query time |
| Implementation effort | Low | Medium | Medium-High |
| Extensibility | Limited | High | High |

**Recommendation:** Option 3 (Hybrid) gives the best balance — broad content from GitHub, fast ranked local search via FTS5, no per-query API costs or rate limits.
