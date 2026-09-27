# Architecture — Instructor Revenue Ledger

This document covers the decisions the brief deliberately left open, why the money-safety
guarantees actually hold, and what's known to be missing.

## 1. Domain model

```
users (role: student|instructor|admin)
  └─ instructors (payout_account_reference)
       └─ courses

subscription_plans (code, term_days, price_cents, platform_fee_bps)
subscriptions (student_id, plan_id, status, starts_at, term_ends_at, accrual_ends_at)
  └─ subscription_courses  — snapshot of (course, instructor) granted at subscribe time
  └─ payments (amount_cents, idempotency_key)
       └─ revenue_allocations (instructor_id, gross_share_cents)  — one per instructor, created once
  └─ refunds

instructor_balances (instructor_id, paid_cents, pending_cents, cached_earned_cents)
payout_batches
  └─ payout_items (instructor_id, amount_cents, status, idempotency_key)
       └─ provider_transactions  — audit trail of every provider call
mock_provider_ledger  — the mock provider's own "external" state
```

Money is stored as **integer cents** (`unsignedBigInteger`) everywhere. No floats, ever —
floating point cannot represent EGP cents exactly and any rounding in a financial ledger
must be a deliberate, auditable decision, not a side effect of IEEE 754.

## 2. Revenue allocation strategy

**When is money earned?** We chose **prorated daily accrual over the term**, not "fully
earned on day one."

- The student pays the whole term up front (`payments.amount_cents`), exactly as
  specified.
- The platform's cut is taken off the top: `platform_fee_cents = floor(amount * fee_bps / 10000)`.
- What's left (the "instructor pool") is split **equally across the distinct instructors
  whose courses this subscription grants access to** — not weighted by watch-time or
  engagement. That would be fairer in theory, but it requires an engagement-tracking
  subsystem this exercise doesn't build, and "equal split among enrolled instructors" is
  a simple, auditable, defensible default when the brief doesn't specify usage-weighting.
  This is recorded once, forever, in `revenue_allocations.gross_share_cents` — it is each
  instructor's **fixed, final entitlement for the whole term**.
- What changes over time is not the entitlement, but how much of it has been **earned**.
  `RevenueAllocation::earnedCentsAsOf($now)` computes this directly from the subscription's
  `starts_at` / `term_ends_at` / `accrual_ends_at`:

  ```
  elapsed  = clamp(days between starts_at and min(now, accrual_ends_at), 0, total_days)
  earned   = floor(gross_share_cents * elapsed / total_days)
  ```

  This is a **direct formula, recomputed on demand** — never a per-day ledger row that
  gets incrementally summed. Two consequences fall out of that on their own:

  1. **No rounding drift.** `floor(gross * elapsed / total)` is exact at `elapsed == total`
     (equals `gross_share_cents` precisely), and monotonically non-decreasing as `elapsed`
     grows. An incremental daily sum of `floor(gross/total)` per day would under-pay by up
     to `total_days - 1` cents by the end of the term; the direct formula never does.
  2. **It scales.** At 500k subscriptions × a few instructors each, one row per
     (subscription, instructor) is a few million rows — not the "tens of millions of
     daily rows" a naive day-by-day ledger would produce for annual plans. "Earned to
     date" for an instructor is one aggregate query over their own allocations, not a scan
     of a per-day table.

**Why this recognition model matters for refunds:** see §4.

## 3. Handling amounts that don't split evenly

Two places produce awkward remainders, and both use the same "leftover cents go to whoever
sorts first, by id" rule — deterministic, reproducible, and the sum is always exactly right:

- **Platform fee → instructor pool**: `floor()`'d. The platform absorbs the sub-cent
  remainder of its own fee; it's their margin, there's no one else to give it to.
- **Instructor pool → N instructors**: `base = intdiv(pool, N)`, `remainder = pool % N`.
  The first `remainder` instructors (ordered by ascending `instructor_id`) each get one
  extra cent. `sum(shares) == pool` always, by construction — see
  `RevenueAllocationServiceTest`.

## 4. Refunds mid-term

The whole mechanism is **one field**: `subscriptions.accrual_ends_at` gets pulled in to
the refund's effective date (never later than it already was, never before today).
Because every instructor's earned-to-date is a live function of that field, shortening it
**is** the clawback:

- Every day after the refund simply never accrues. No reversal ledger rows, no negative
  balances, no touching `revenue_allocations.gross_share_cents` (which stays the
  student's original full-term entitlement, for audit).
- The **refund amount** paid back to the student is computed the same way, in reverse:
  `refund_cents = floor(payment.amount_cents * remaining_days / total_days)`.
- This only works cleanly because a refund's effective date is "now" (or later) — never
  backdated before the moment the payout process could already have paid against it. See
  §7, Known Limitations, for the backdated case.
- **Why accrual (not cash-basis) matters here**: if instructor shares were "fully earned
  on day one," a mid-term refund would have to claw back money that may already be
  correctly earned *and already paid out* for lessons already delivered — a genuinely
  messy negative-balance problem. Accrual sidesteps it entirely: you can only ever refund
  what hasn't accrued yet.

## 5. Idempotency approach — the core of the payout pipeline

**The central design decision**: payout amounts are never fixed batch numbers, they are
always **"whatever is currently outstanding"**, computed live and reserved under a row
lock. That single choice is what makes every failure scenario in the brief safe.

```
outstanding = earned_to_date - paid_cents - pending_cents
```

`instructor_balances` (one row per instructor) is the only table every payout write locks
with `SELECT ... FOR UPDATE`, inside a transaction:

1. **Claim** (`PayoutService::claimOrResume`): lock the instructor's balance row. If an
   unresolved (`pending`/`processing`) `payout_items` row already exists for this
   instructor, **return it** instead of claiming again — this is what makes retries safe.
   Otherwise, compute outstanding; if `<= 0`, nothing to do. Otherwise create a
   `payout_items` row with a fresh UUID `idempotency_key` and increment
   `pending_cents` by that exact amount, atomically, in the same transaction as the read.
2. **Settle** (`PayoutService::settle`): call the provider with that idempotency key. React
   to the result (§6).
3. **Apply the result**: re-fetch and lock the `payout_items` row **fresh** (not the
   possibly-stale in-memory object the caller holds) before trusting its status. If it's
   already resolved, do nothing. Otherwise move `pending_cents → paid_cents` (success) or
   release `pending_cents` (failure), inside a transaction that also locks
   `instructor_balances`.

That last point — re-locking and re-checking the *payout_items* row itself before
applying a result, rather than trusting the in-memory PHP object — is not decorative. It
is the thing that stops **two independent code paths both holding a reference to the same
logical claim from both applying the same result**, e.g. two workers that both called
`claimOrResume()` and got the same item back before either settled. `PayoutIdempotencyTest`
(`two concurrent workers racing on the same instructor still only pay once`) exercises
exactly this and was how a real double-application bug was caught and fixed during
development (see `/docs/AI_USAGE.md`).

**Why this covers every scenario in the brief:**

| Scenario | What actually happens |
|---|---|
| Payout process runs twice (overlapping schedule, manual re-trigger, two servers) | Second run's `claimOrResume()` either resumes the first's unresolved claim (row lock serialises them) or computes `outstanding == 0` and does nothing. |
| A queued job is retried after a crash | The retry re-enters `claimOrResume()`, finds the same unresolved `payout_items` row (same `idempotency_key`), and resumes it — it never creates a second claim. |
| `PayInstructorJob` is dispatched twice for the same instructor | `ShouldBeUnique` (keyed on `instructor_id`) collapses duplicate *queued* dispatches into one; even if that lock is unavailable, the DB-level claim logic above is the real backstop. |

A `Cache::lock` in `payouts:run` only prevents two overlapping *invocations of the command
itself* from redundantly walking the whole instructors table and dispatching duplicate
jobs — it is an efficiency nicety, explicitly **not** a correctness requirement. If that
lock is unavailable (cache down, no shared cache between two servers), the command still
behaves safely; it just might dispatch some redundant jobs, which the per-instructor claim
absorbs for free.

## 6. Provider timeout handling

The mock provider (`App\Payments\MockPaymentProvider`) simulates three outcomes on
`payout()`: succeeded, failed permanently, and **timeout** — where the real result (which
it decides and stores immediately, exactly once per idempotency key) is witheld from the
caller and can only be discovered later via `status()`, or by calling `payout()` again with
the same key. This mirrors real providers (Stripe, etc.) closely enough to exercise the
actual failure mode described in the brief.

- **Timeout → `PayoutItemStatus::Processing`.** The item stays exactly where it was:
  money reserved (`pending_cents`), nothing paid, nothing failed. Guessing either way
  would be wrong; the design simply defers the decision.
- `PayInstructorJob` schedules a `CheckPayoutStatusJob` (backoff: 30s, 60s, 120s, ...).
  It calls `PayoutService::reconcile()`, which asks `status()` and applies whatever it
  gets back — through the same locked, re-checked `applyResult()` path as `settle()`, so a
  duplicate/late reconciliation job is a safe no-op.
- **If the provider never resolves** after `MAX_ATTEMPTS` (6) status checks, the item is
  flagged `requires_manual_review = true` and left alone — the system stops guessing and
  hands it to a human rather than silently assuming success or failure either way. This
  is intentional: for money, "I don't know yet" is a valid, representable state, not a bug
  to paper over.

## 7. Known limitations / what I'd do next in production

- **Backdated refunds.** If a refund's effective date is *before* the last moment the
  payout process already paid against, the current design can't claw back money already
  paid — `accrual_ends_at` can only move accrual into the past for days not yet paid. A
  production system would need an explicit reversal/negative-ledger-entry mechanism for
  this edge case (deliberately out of scope here — see the brief's "spot the ambiguous
  edges" framing).
- **`cached_earned_cents` staleness.** The Filament dashboard reads a cache refreshed by
  `payouts:refresh-earnings` (scheduled every 5 minutes), not a live computation, because
  live-computing "earned to date" for every row of a paginated admin table doesn't scale
  to tens of millions of `revenue_allocations` rows. The payout pipeline itself **never**
  trusts this cache — it always recomputes live under the balance lock. In production I'd
  also add a "last refreshed" staleness warning in the UI if the scheduled job stops
  running.
- **No DB-level backstop on "at most one unresolved claim per instructor."** That
  invariant is currently enforced entirely in application code (`claimOrResume`'s
  check-then-create under a row lock). A belt-and-suspenders partial unique index (e.g. a
  generated column that's non-null only while `status IN ('pending','processing')`, with a
  unique index on it) would catch an application bug that bypassed the lock; I didn't add
  it here to avoid a MySQL/SQLite portability wrinkle for a guarantee the row lock already
  provides, but it's a reasonable production hardening step.
- **Currency**: single-currency (`EGP`) throughout. Multi-currency would need the
  allocation math (and the platform fee) to operate in a consistent settlement currency.
- **Mock provider is in-process.** A real integration would need webhook-based
  confirmation in addition to polling `status()`, and idempotency keys would need to be
  provider-namespaced.

## 8. Scaling to 500,000 subscriptions / tens of millions of records

- Revenue allocation is `O(instructors per subscription)` rows, not `O(term days)` — see
  §2. At ~3 instructors/subscription average, 500k subscriptions is ~1.5M
  `revenue_allocations` rows, comfortably indexed on `(instructor_id, subscription_id)`.
- The payout command processes instructors via `chunkById()`, never loading the whole
  table into memory.
- Each instructor's payout is one queued job; queue workers scale horizontally for free
  because correctness lives in the per-instructor DB lock, not in in-process state.
- `earnedToDateCents()` is one query over an instructor's own allocations — bounded by how
  many *courses* one instructor teaches across *their own* subscriptions, not by platform-wide
  volume.

## Senior bonus (discussion only — not implemented)

**How would a mid-term plan upgrade (e.g. monthly → annual, or annual with a fair price
adjustment) work in this design?**

Treat it as **the same two primitives already built, applied together**:

1. **Close out the old term early**, exactly like a refund: shorten the old
   subscription's `accrual_ends_at` to today. Its instructors keep everything already
   earned; nothing further accrues under the old allocation. Compute the *credit* the
   student is owed for the unused remainder the same way `RefundService` already does:
   `credit = floor(old_payment.amount_cents * remaining_days / old_total_days)`.
2. **Open a new term for the new plan**, but only for the remaining period being upgraded
   into (or a fresh full term, depending on the product rule chosen) — a new `Subscription`
   row (or an amendment to the same one) with its own `starts_at`/`term_ends_at`, and a
   new `Payment` for `new_plan_prorated_cost - credit` (or `0`/a refund if the credit
   exceeds the new cost).
3. **Allocate the new payment** with the existing `RevenueAllocationService`, passed the
   new course/instructor set and the (possibly partial-term) day count — no new machinery
   needed, since allocation was already written in terms of "a payment, a term length, a
   set of instructors," not "a payment must span a plan's *default* term length."

This is exactly why the accrual/proration model earns its complexity: an upgrade is not a
new code path, it's "run the refund logic, then run the allocation logic" — both of which
already have to exist and be correct for the required scenarios. The main product
decision left for the team, not the engineer, is *pricing policy* for the new term
(prorate the new plan's price for just the remaining days, vs. charge a fresh full term
and extend the end date) — that's a business call, not an architecture one.
