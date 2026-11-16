---
name: seo-refresh
description: Refresh .ai/seo-snapshot.md for bilis.app from OpenSEO (Search Console, rank tracker, site audit, keyword metrics, SERP competitors) so the cloud growth routine, which can't reach OpenSEO, has current search data. Local only; needs the openseo MCP server. Use when the owner says "refresh SEO", "update the SEO snapshot" or runs /seo-refresh.
---

# Refresh the SEO snapshot

The daily cloud routine (see `.ai/growth-loop.md`) has no OpenSEO or Search Console access. Its only search data is `.ai/seo-snapshot.md`. This skill rebuilds that file from live data and opens a PR.

**Requires** the `openseo` MCP server. If its tools aren't available or say to sign in again, ask the owner to run `/mcp` and re-authenticate `openseo`. In a cloud session, stop and say this skill is local-only.

OpenSEO project: `bilis.app`, id `7e90b0e8-8d5c-49d4-8717-b07e1df4c2d9`, market US/en, Search Console property `sc-domain:bilis.app`, weekly desktop rank tracker `2bd0d060-8a5a-43d2-a415-71b7c1edaf28` (34 keywords).

## Steps

1. **Read the current state:**
   - `.ai/seo-snapshot.md`;
   - the public pages: `routes/web.php` (tools, compare, docs, blog), `App\Services\Tools\ToolCatalog`, `resources/docs/**` and `resources/blog/**` front matter;
   - the open PRs (`gh pr list`), merged `loop/` PRs since the last snapshot, and recent `.ai/loop-runs/*.md`;
   - `mcp__openseo__get_project_context` (check the research log so you don't re-buy research).

2. **Search Console** (free): `mcp__openseo__get_search_console_performance` with
   - `dateRange: last_28_days` (and `last_3_months` while data is thin), dimensions `["query", "page"]`, `rowLimit: 500`;
   - dimensions `["page"]`;
   - striking distance: `minPosition: 5, maxPosition: 25, minImpressions: 5` (raise to 20 once traffic grows), dimensions `["query", "page"]`.

3. **Rank tracker** (free to read): `mcp__openseo__get_rank_tracker` with the tracker id; note movers since the last snapshot. Add newly targeted keywords with `add_rank_tracking_keywords` only after estimating cost and with the owner's OK.

4. **Site audit** (free crawl without JS): `mcp__openseo__run_site_audit` with `url: https://bilis.app/`, `maxPages: 200`. Poll `get_audit_status`, then read `get_audit_issues`.

5. **Keyword metrics** (costs credits): one `mcp__openseo__get_keyword_metrics` call with `includeMonthlyTrends: false`, covering every keyword in the snapshot's tables, the "not yet measured" list, and new Search Console queries with ≥ 5 impressions. Keep it to one call (≤ 700 keywords). At most one `research_keywords` call (≤ 5 seeds) when a new topic needs discovery. Ask the owner before anything that could exceed 2,000 credits.

6. **Competitors** (credits, optional; at most every second refresh): `mcp__openseo__find_serp_competitors` on the top 25 targets, organic only, excluding `bilis.app`.

7. **Rewrite `.ai/seo-snapshot.md`.** Keep its structure: snapshot date; Search Console summary and striking-distance table; strategy notes; target tables with status and position; not-yet-measured and dead-end lists; competitors; audit summary; **a re-prioritised work queue** the growth agent can act on without search access (each item names a concrete file and the evidence); owner-only actions; a changelog row. Drop work-queue items that merged PRs completed. Never invent a volume.

8. **Record it:** append a research-log entry with `mcp__openseo__update_project_context` (`appendResearchLog`) summarising what was bought and found; update key pages and competitors there if they changed.

9. **Ship:** create branch `seo/snapshot-YYYY-MM-DD` from `main`, commit only `.ai/seo-snapshot.md`, push, and open a PR titled "SEO snapshot YYYY-MM-DD". Use the attribution lines from the session. Report the top three work-queue items and any credits spent.
