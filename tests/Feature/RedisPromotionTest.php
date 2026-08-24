<?php

use AbdulmajeedJamaan\QueuePromoter\Tests\Fixtures\RecordingJob;
use Illuminate\Support\Facades\Queue;

/**
 * Integration tests against a real Redis/Valkey server, used the way an app
 * would: dispatch jobs, run queue:promote, then run a worker. Fails hard (rather
 * than skipping) if no server is reachable.
 */
beforeEach(function () {
    try {
        app('redis')->connection()->flushdb();
    } catch (Throwable $e) {
        $this->fail('Redis/Valkey is not reachable; integration tests require a running server. '.$e->getMessage());
    }

    RecordingJob::reset();
});

it('promotes a due delayed job so a worker can then process it', function () {
    // Dispatch a job to run a few minutes from now.
    RecordingJob::dispatch('default')
        ->onConnection('redis')
        ->onQueue('default')
        ->delay(now()->addMinutes(5));

    // It comes due, but with no workers nothing has popped it onto the ready list.
    $this->travel(6)->minutes();

    expect(readyJobCount('default'))->toBe(0);

    // The promoter surfaces it on the ready list — no worker needed.
    $this->artisan('queue:promote', [
        'connection' => 'redis',
        '--once' => true,
        '--sleep' => 0,
    ])->assertSuccessful();

    expect(readyJobCount('default'))->toBe(1);

    // A worker can now pick it up and run it.
    $this->artisan('queue:work', [
        'connection' => 'redis',
        '--once' => true,
        '--queue' => 'default',
    ])->assertSuccessful();

    expect(RecordingJob::$handled)->toBe(['default']);
});

it('promotes an expired reserved job back so it can be retried', function () {
    RecordingJob::dispatch('default')
        ->onConnection('redis')
        ->onQueue('default');

    expect(readyJobCount('default'))->toBe(1);

    // Stand in for a worker that reserved the job then crashed: pop() reserves
    // it, and never deleting it leaves it stuck in :reserved.
    app('queue')->connection('redis')->pop('default');

    expect(readyJobCount('default'))->toBe(0)
        ->and(RecordingJob::$handled)->toBe([]);

    // retry_after (90s) elapses with no worker to reclaim it.
    $this->travel(91)->seconds();

    $this->artisan('queue:promote', [
        'connection' => 'redis',
        '--once' => true,
        '--sleep' => 0,
    ])->assertSuccessful();

    expect(readyJobCount('default'))->toBe(1);

    // A worker can now run it.
    $this->artisan('queue:work', [
        'connection' => 'redis',
        '--once' => true,
        '--queue' => 'default',
    ])->assertSuccessful();

    expect(RecordingJob::$handled)->toBe(['default']);
});

it('does not promote a paused queue, then resumes once unpaused', function () {
    RecordingJob::dispatch('default')
        ->onConnection('redis')
        ->onQueue('default')
        ->delay(now()->addMinutes(5));

    $this->travel(6)->minutes();

    // A paused queue is left untouched, so the due job stays parked.
    Queue::pause('redis', 'default');

    $this->artisan('queue:promote', [
        'connection' => 'redis',
        '--once' => true,
        '--sleep' => 0,
    ])->assertSuccessful();

    expect(readyJobCount('default'))->toBe(0);

    // Once resumed, the job is promoted as normal.
    Queue::resume('redis', 'default');

    $this->artisan('queue:promote', [
        'connection' => 'redis',
        '--once' => true,
        '--sleep' => 0,
    ])->assertSuccessful();

    expect(readyJobCount('default'))->toBe(1);
});

it('promotes due jobs across multiple comma-separated queues in one pass', function () {
    RecordingJob::dispatch('high')
        ->onConnection('redis')
        ->onQueue('high')
        ->delay(now()->addMinutes(5));

    RecordingJob::dispatch('default')
        ->onConnection('redis')
        ->onQueue('default')
        ->delay(now()->addMinutes(5));

    $this->travel(6)->minutes();

    expect(readyJobCount('high'))->toBe(0)
        ->and(readyJobCount('default'))->toBe(0);

    $this->artisan('queue:promote', [
        'connection' => 'redis',
        '--queue' => 'high,default',
        '--once' => true,
        '--sleep' => 0,
    ])->assertSuccessful();

    expect(readyJobCount('high'))->toBe(1)
        ->and(readyJobCount('default'))->toBe(1);
});
