# AI Restoration Phase C — 2026-05-19

## Context

Phases A + B addressed *correctness* of the AI auto-reply (no more
"พรุ่งนี้ที่ 17 พ.ค." leaking through). Phase C adds the *operational*
layer: cost control, latency, observability. Without it we cannot tell
whether Gemini is healthy, whether the matcher is doing what we think,
or whether bursts of identical questions are burning quota.

User picked "A + B + C — บูรณะเต็ม" on 2026-05-19. A and B landed
earlier the same day; this log is for C.

## Decision

Three self-contained additions, all behind feature-detected requires so
they degrade silently if a helper is missing (defensive against partial
deploys):

### 1. Day-bounded answer cache (`includes/ai_cache_helper.php`)

Schema: `sys_ai_answer_cache (cache_key PK, question, answer, category,
confidence, model, hit_count, last_hit_at, created_at, expires_at)`.

Cache key = SHA256(`"ai-qa:{YYYY-MM-DD}:{normalized question}"`) where
date is Asia/Bangkok. Normalization = lower-case + collapse whitespace +
strip trailing punctuation, so trivial differences ("เปิดกี่โมง",
" เปิดกี่โมง?", "เปิดกี่โมง  ") hit the same slot.

TTL = end of calendar day (Asia/Bangkok 23:59:59). Day boundary is the
natural "stale by default" point for clinic facts — admin can also call
`ai_cache_purge_all()` (POSTed as the `cache_purge` action) after pushing
a knowledge update mid-day.

**Time-sensitive questions BYPASS the cache.** The Phase A detector +
per-FAQ flag still short-circuit straight to `ai_qa_generate_answer()`
because their answer changes within the day; cache would defeat that.

### 2. Telemetry (`includes/ai_telemetry_helper.php`)

Schema: `sys_ai_telemetry (id, event_type, source, model, elapsed_ms,
error_msg, line_user_hash, meta_json, created_at)`.

Events emitted (all fire-and-forget — telemetry failure never blocks):

| event_type             | emitted from                                 |
|------------------------|----------------------------------------------|
| gemini_call            | `ai_qa_generate_answer` (before round-trip)  |
| gemini_success         | `ai_qa_generate_answer` (after parse OK)     |
| gemini_fail            | `ai_qa_generate_answer` (catch, rethrow)     |
| cache_hit              | `ai_qa_generate_answer` (cache short-circuit)|
| cache_miss             | `ai_qa_generate_answer` (before generate)    |
| faq_hit                | `ai_qa_refresh_if_stale` (Phase 1 match)     |
| bypass_time_sensitive  | `ai_qa_match_faq` (Phase A short-circuit)    |
| fallback_used          | `ai_qa_match_faq` (generate fail → FAQ)      |
| thumbs_up / thumbs_down | `portal/ajax_ai_feedback.php::save_rating`  |

Privacy: LINE user ids are stored as `substr(sha256("ai-qa-user:"+id), 0, 32)`
not raw. Healthcare data hygiene.

`ai_telemetry_summary(window_hours = 24)` rolls up derived metrics for
the dashboard: `gemini_fail_rate`, `cache_hit_rate`, `fallback_used`,
`satisfaction_rate` (thumbs_up / (up + down)).

### 3. Admin endpoints (`portal/ajax_ai_qa.php`)

Three new actions, gated by existing `access_ai` check:

- `health_summary` — telemetry roll-up (sliding window) + cache stats
- `telemetry_recent` — last N events for debug timeline
- `cache_purge` — wipe `sys_ai_answer_cache` (admin "I just pushed a
  knowledge update" button)

No UI panel in this commit — the endpoints are stable so a future
session (or whoever wires the Lab dashboard) can build the widget
without backend changes.

## Alternatives Considered

1. **Per-question TTL instead of day-bounded** — more accurate but
   adds bookkeeping (when did the underlying fact change?). Day
   boundary maps to admin workflow (they think in days) and is
   correctness-bounded by the existing Phase A bypass.

2. **Redis / Memcached cache** — no Redis in the stack. MySQL is fine
   at the volume we're at (single clinic, hundreds of questions/day).
   Schema is portable if we ever move.

3. **Telemetry to a SaaS (Sentry custom events, Datadog)** — Sentry
   is wired already but is for *errors*. AI telemetry is operational
   metric, not error. Local MySQL keeps it self-contained + cheap to
   query from the Lab UI.

4. **Cache by hash-of-question only (no date)** — simpler, but then
   yesterday's "เปิดวันนี้กี่โมง" leaks into today. The date prefix
   gives free invalidation.

5. **No telemetry, just `error_log`** — error_log is text-only, hard
   to aggregate, and clears on log rotation. A queryable table is
   worth the schema even if no UI consumes it yet.

## Consequences

- LINE auto-reply latency on identical questions drops from ~1.5–3s
  to ~10ms after the first hit of the day.
- Gemini quota burn is bounded: same question asked N times costs 1
  Gemini call, not N.
- "Why did the bot say X?" investigations now have a queryable
  source-of-truth (`sys_ai_telemetry` + `sys_ai_answer_cache`). The
  matcher still emits `ai_qa_debug_log` to error_log for the legacy
  flow.
- Schema is auto-created on first write of each table — no migration
  script needed.
- Cron candidates (not scheduled in this commit, exported only):
  `ai_cache_purge_expired($pdo)` and
  `ai_telemetry_purge_older_than($pdo, 30)`. Wire when needed.

## Reference

- Commit: `bdc9475` — main, 2026-05-19
- Files:
  - `includes/ai_cache_helper.php` (new — schema, get/put, purge, stats)
  - `includes/ai_telemetry_helper.php` (new — schema, log, summary, recent, purge)
  - `includes/ai_qa_helper.php` (require + integrate in `ai_qa_generate_answer` and `ai_qa_match_faq`)
  - `portal/ajax_ai_qa.php` (three new admin actions)
  - `portal/ajax_ai_feedback.php` (emit thumbs telemetry from existing save_rating)
- Builds on: A `5752680`, B `1166275` + polish `ff6d6ac`
- Pattern doc: `AI/knowledge/settings-singleton-patch-merge.md` (from
  the FAQ settings drift fix — same defensive-helper style applied here)

## Follow-up

- Wire a `<section>` in `portal/_partials/ai_qa_lab.php` calling
  `?action=health_summary` + showing the metrics + a "Bust cache"
  button (calls `cache_purge`).
- Add cron entry to invoke `ai_telemetry_purge_older_than($pdo, 30)`
  nightly (probably alongside existing `cron/purge_error_logs.php`).
- Consider per-question TTL once we have hit_count data to know which
  questions actually benefit from longer caching.
