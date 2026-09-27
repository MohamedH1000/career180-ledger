<?php

use App\Jobs\PayInstructorJob;
use App\Models\Instructor;
use App\Models\PayoutBatch;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;

test('payouts:run dispatches one job per instructor and records a batch', function () {
    Bus::fake();

    Instructor::factory()->count(3)->create();

    Artisan::call('payouts:run');

    Bus::assertDispatchedTimes(PayInstructorJob::class, 3);
    expect(PayoutBatch::query()->count())->toBe(1);
    expect(PayoutBatch::query()->first()->status)->toBe('completed');
});

test('an overlapping invocation while one is already dispatching is skipped safely', function () {
    Bus::fake();

    Instructor::factory()->count(2)->create();

    $lock = \Illuminate\Support\Facades\Cache::lock('payouts:run-dispatch', 300);
    $lock->get();

    try {
        Artisan::call('payouts:run');

        // The overlapping run should not have dispatched anything or created a batch.
        Bus::assertNotDispatched(PayInstructorJob::class);
        expect(\App\Models\PayoutBatch::query()->count())->toBe(0);
    } finally {
        $lock->release();
    }
});
