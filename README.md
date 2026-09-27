# Instructor Revenue Ledger

Career 180 hiring quest submission — the money core of an LMS: take subscription
payments in, work out what each instructor is owed, and pay them out safely even when
the payout process runs twice, jobs get retried, or the payment provider is unreliable.

See [`/docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) for the design decisions and
[`/docs/AI_USAGE.md`](docs/AI_USAGE.md) for how AI was used to build this.

## Stack

Laravel 11 · Filament v3 · Pest 3 · MySQL (SQLite for tests) · PHP 8.3

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
```

By default `.env` is configured for SQLite for a quick local run (`database/database.sqlite`
is created automatically by `composer create-project`). To use MySQL instead, set in `.env`:

```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=career180_ledger
DB_USERNAME=root
DB_PASSWORD=
```

Then:

```bash
php artisan migrate --seed
php artisan serve
```

Visit `/admin` and log in with the seeded admin account:

- **Email:** `admin@career180.test`
- **Password:** `password`

The seeder creates 15 instructors, 3 subscription plans (monthly/quarterly/annual), 60
subscriptions with real payments run through `RevenueAllocationService`, a handful of
mid-term refunds, and one real payout pass through the mock provider — so the Filament
screen shows non-trivial, realistic numbers immediately.

## Running the payout process

```bash
php artisan payouts:run
```

Dispatches one queued job per instructor that currently has an outstanding balance. Safe
to run again immediately, or concurrently — see `/docs/ARCHITECTURE.md` §5 for why.

For queued jobs to actually run outside of `QUEUE_CONNECTION=sync`, run a worker:

```bash
php artisan queue:work
```

To refresh the cached "earned to date" figures the Filament dashboard reads:

```bash
php artisan payouts:refresh-earnings
```

Both commands are scheduled in `routes/console.php` (`payouts:run` daily,
`payouts:refresh-earnings` every 5 minutes) — wire up `php artisan schedule:work` (or a
real cron entry calling `schedule:run` every minute) to run them automatically.

## Running the tests

```bash
php artisan test
# or directly:
vendor/bin/pest
```

30 tests / 142 assertions, covering (see `tests/`):

- Revenue allocation math: platform fee, equal split, remainder-cent distribution,
  idempotent re-allocation, the zero-instructor edge case.
- Accrual: nothing earned before start, exact fractions mid-term, no rounding drift at
  full term, monotonicity.
- Refunds: mid-term proration, day-zero full refund, post-term refund is zero.
- The mock payment provider itself: idempotent replay, timeout-then-reveal, races on a
  fresh key.
- **The three required failure scenarios**, each with a dedicated, explicitly-named test
  in `tests/Feature/Payouts/PayoutIdempotencyTest.php`:
  - Running `payouts:run` twice never double-pays.
  - A retried/duplicated `PayInstructorJob` never double-pays, including the
    claim-then-crash-then-resume case.
  - A provider timeout followed by a delayed confirmation (success or failure) is applied
    exactly once, including when the reconciliation itself is duplicated.
- The Artisan command's dispatch behaviour and its overlap-skipping lock.
- The reconciliation job's backoff/manual-review escalation, and its no-op on an
  already-resolved item.

A saved run of this exact suite is at [`docs/test-results.txt`](docs/test-results.txt).
When you run it yourself, take a screenshot of the passing output for your submission
evidence — this repo doesn't fabricate one on your behalf.

## Assumptions made

Several rules were deliberately left unspecified in the brief. Full reasoning for each is
in `/docs/ARCHITECTURE.md`; in short:

1. **Revenue split**: platform fee off the top, remainder split *equally* across the
   distinct instructors whose courses a subscription grants access to (not
   engagement-weighted — that needs tracking infrastructure out of scope here).
2. **When money is earned**: prorated daily accrual over the term, not fully earned on
   day one. This is what makes mid-term refunds clean (see below).
3. **Refund proration**: a refund shortens the subscription's accrual cutoff to the
   refund's effective date; the student is refunded the unearned remainder, computed the
   same way accrual is computed. Refunds are assumed to take effect "now or later," never
   backdated before money may already have been paid out (see Known Limitations).
4. **Rounding**: everything is integer cents; any remainder from an uneven split goes to
   the platform (its own fee) or to the lowest-id instructors (one cent each), so the sum
   is always exactly right and never drifts.
5. **Payout eligibility**: an instructor is paid whatever is currently outstanding
   (earned − paid − reserved), every run — there's no minimum payout threshold or payout
   schedule per instructor beyond the platform-wide `payouts:run` cadence.
6. **Currency**: single currency (EGP) throughout.

## Filament screen

`/admin/instructors` — read-only, as specified: instructor balances (earned to date,
paid out, pending, outstanding) and, per instructor, full payout history including
provider references and failure reasons.
