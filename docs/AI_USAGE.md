# AI Usage

Honest account of how this submission was built, per the challenge's AI Usage Policy.

## 1. How AI was used

The entire implementation was built end-to-end with **Claude Code** (Claude, Anthropic's
agentic coding CLI) in a single working session, operating directly in the repository:
scaffolding the Laravel project, writing every migration/model/service/job/command,
writing and iterating on the Pest test suite, building the Filament resource, and writing
this documentation. Claude also stood up a local, no-admin-rights PHP 8.3 + Composer
toolchain to actually run `composer install`, `php artisan migrate`, and the full test
suite for real, rather than just producing code that "should" work.

This was not a single generate-and-accept pass. The workflow was iterative:
build → run the real test suite → read the real failure → fix → re-run, repeated until
green. That loop is what caught the most important bug in this codebase (§3).

## 2. Main prompts / workflow

1. Given the full brief, Claude first surfaced the ambiguous, deliberately-unspecified
   design questions the brief calls out and asked for a human decision on each, with a
   recommended default and the trade-off for each option, rather than silently picking
   one:
   - How should one payment be split across multiple instructors?
   - When does money count as "earned," and how does that affect refunds?
   - Where should the project live / how does it reach GitHub?
   - Given no PHP/Composer/MySQL was available locally, should Claude install a real
     toolchain to actually run things, or hand over unexecuted code?
2. With those decisions locked in, Claude scaffolded Laravel + Filament + Pest, then
   worked through the domain layer in dependency order: migrations → models → the mock
   payment provider → `RevenueAllocationService` → `RefundService` → `PayoutService` →
   the queued jobs → the Artisan command → the Filament screen → the seeder.
3. Each service was paired with unit/feature tests targeting the specific correctness
   property it exists for (rounding exactness, monotonic accrual, idempotent claims,
   provider-timeout resolution), not generic CRUD tests.
4. The whole suite, the seeder, and the Filament screen were run for real (not just
   read) — migrations against SQLite, `vendor/bin/pest`, and a live `php artisan serve` +
   headless-browser click-through of `/admin/instructors` — and issues found that way
   were fixed before being called done.

## 3. What AI generated vs. what required human/engineering judgment

**Fully AI-generated, human-reviewed:** the Laravel/Filament boilerplate, migrations,
Eloquent models and relationships, the Pest test scaffolding, and the documentation prose.

**The judgment calls that actually mattered — and were made explicitly, not defaulted
into:**

- **The accrual/proration revenue-recognition model** (§2, §4 of `ARCHITECTURE.md`) was
  chosen specifically *because* of how it simplifies refunds — this is a design decision
  with a stated trade-off (it wasn't "the first idea," it was chosen after considering
  cash-basis recognition and rejecting it for making refunds require clawing back
  possibly-already-paid money).
- **The "outstanding = earned − paid − pending, recomputed live under a row lock" claim
  pattern** in `PayoutService` is the single mechanism that has to correctly handle every
  failure scenario in the brief at once (double-run, retried jobs, provider ambiguity).
  Getting this right required rejecting a simpler-looking first design (a fixed "batch
  amount" computed once per `payouts:run` invocation) in favour of a per-instructor,
  always-recomputed claim, specifically because a fixed batch amount doesn't self-correct
  when a previous attempt partially succeeded.
- **A real bug was found by the test suite, not spotted in review, and the fix is the
  most important correctness fix in the codebase**: `PayoutService::applyResult()`
  originally trusted the in-memory `PayoutItem` object passed to it. A test written to
  cover "two concurrent workers racing on the same instructor" (deliberately simulating
  two independent `claimOrResume()` callers before either settles) failed with a
  double-payment (16000 instead of 8000). The fix — re-fetching and row-locking the
  `payout_items` record itself inside `applyResult()`, rather than trusting whichever
  in-memory copy the caller happened to hold — is now the actual guarantee against
  double-payment, and is called out explicitly in `ARCHITECTURE.md` §5. This is exactly
  the kind of race a superficial "looks idempotent" implementation misses, and it was
  only caught because the test was written to model concurrency at the object level, not
  just "call the method twice."
- **Rejected**: a per-day materialised ledger row (one row per instructor per day of the
  term). It's the "obvious" way to model accrual and matches the brief's "tens of
  millions of records" framing, but it doesn't scale as well as a direct closed-form
  formula recomputed on demand, and introduces a whole extra write-amplification problem
  at subscribe time for annual plans. Rejected in favour of one row per
  (subscription, instructor) with the amount computed analytically — see
  `ARCHITECTURE.md` §2 for the full reasoning, including why this still doesn't lose the
  "exact at term end" property a day-by-day sum would risk via rounding drift.
- **Rejected**: relying solely on `ShouldBeUnique` queue-level locking as the safety
  mechanism. It's real and included (belt-and-suspenders, cheap), but the brief's "two
  servers at once" scenario means a cache-backed application lock alone is not
  sufficient — the actual guarantee had to live in a database row lock, which is the one
  thing guaranteed to be consistent across processes/servers sharing one database.

## 4. What differentiates this submission

- The idempotency guarantee is demonstrated **against a bug that was actually caught and
  fixed during development**, not just asserted — §3 above and the git history show the
  before/after.
- The revenue-recognition model was chosen with refunds specifically in mind, so refunds,
  the payout pipeline, and even the (unimplemented, discussed) mid-term plan upgrade all
  reduce to the same two primitives instead of needing separate special-case code paths.
- Everything claimed to work was actually run: a real Laravel app booted, migrated
  against a real database, tested with a real (not narrated) passing Pest suite, and
  clicked through in a real browser against the actual Filament screen.

## 5. Trade-offs and improvements intentionally chosen

See `/docs/ARCHITECTURE.md` §7 ("Known Limitations") for the full list — in summary:
backdated refunds aren't handled (out of scope, flagged explicitly), the admin dashboard
reads a periodically-refreshed cache rather than computing live at request time (a
scale trade-off, with the payout pipeline itself never trusting that cache), and there's
no additional DB-level partial-unique-index backstop beyond the row lock (a reasonable
next hardening step, not added here to avoid a MySQL/SQLite portability wrinkle for a
guarantee the row lock already provides).

---

*Before the review session, re-read this file and `/docs/ARCHITECTURE.md` end to end and
be ready to defend, modify, or argue against any part of it live — that's explicitly what
this quest evaluates, and no AI-assisted transcript should substitute for actually
understanding the code you're submitting.*
