# Queue Promoter

> **Enable true scale-to-zero for Laravel Redis queues.**
>
> Queue Promoter is a lightweight `queue:work`-style daemon that promotes **due delayed** and **expired reserved** jobs onto Laravel's Redis ready queue—allowing autoscalers like **KEDA** to wake workers even when they've scaled down to zero.

## The Problem

Laravel's Redis queue only promotes due delayed and expired reserved jobs when a worker calls `pop()`.

That works perfectly while at least one worker is running.

In scale-to-zero environments, however, all workers are intentionally stopped when the ready queue becomes empty.

That creates a deadlock:

1. A delayed job becomes due (or a reserved job expires).
2. No worker is running, so nothing calls `pop()`.
3. The ready queue stays empty.
4. The autoscaler sees no work and keeps workers at zero.

**The queue can never wake itself back up.**

## The Solution

Run a single `queue:promote` process.

Instead of executing jobs, it only:

- Promotes due delayed jobs.
- Retries expired reserved jobs.
- Pushes them onto the Redis ready queue.

Once jobs appear on the ready queue, the autoscaler starts your normal queue workers.

```text
          Delayed Jobs
               │
               ▼
        Redis :delayed
               │
               │
     Expired Reserved Jobs
               │
               ▼
        Queue Promoter
               │
               ▼
      Redis Ready Queue
               │
          LLEN > 0
               │
               ▼
      KEDA / Autoscaler
               │
               ▼
      Laravel Queue Workers
```

Queue Promoter never reserves or executes jobs.

It only makes queued work visible again.

> [!NOTE]
> Queue Promoter is only needed for the **Redis** queue driver.
>
> The `database`, `sqs`, and `beanstalkd` drivers determine whether jobs are due when retrieving them, so they have nothing to promote.

## Why not just keep one worker running?

You certainly can—but that largely defeats the purpose of scaling to zero.

In many production deployments, different groups of queues are processed by separate worker deployments. Without Queue Promoter, **each deployment** needs at least one idle worker running to ensure delayed and expired jobs are promoted.

Queue Promoter replaces all of those idle workers with a single lightweight process that serves **all configured queues**, allowing every worker deployment to scale independently to zero.

**No matter how many worker deployments you have, a single Queue Promoter instance can serve all configured queues.**

## Installation

```bash
composer require abdulmajeed-jamaan/laravel-queue-promoter
```

The service provider is auto-discovered.

## Usage

```bash
php artisan queue:promote

php artisan queue:promote redis --queue=high,default

php artisan queue:promote redis --once

php artisan queue:promote redis --sleep=1 --max-time=3600
```

> [!IMPORTANT]
> Like `queue:work`, Queue Promoter only processes the queues specified via `--queue`.
>
> Make sure every queue that receives delayed jobs is included. A delayed job on an unlisted queue will never be promoted.

Run it under Supervisor, systemd, or Kubernetes. It supports graceful shutdown via `SIGTERM` and `queue:restart`.

Or run a single promotion pass from Laravel's scheduler:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('queue:promote redis --once')
    ->everyFifteenSeconds();
```

## How it works

Queue Promoter reuses Laravel's existing Redis queue implementation.

Each loop it:

1. Migrates due delayed jobs.
2. Retries expired reserved jobs.
3. Stops before reserving the next job.

No jobs are reserved.

No jobs are executed.

It simply makes work available for your existing queue workers.

## Testing

```bash
composer test
```

Tests run against a real Redis-compatible server (Redis or Valkey).

## License

Queue Promoter is open-source software licensed under the MIT License.