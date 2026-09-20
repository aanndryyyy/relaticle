# Deploying on Laravel Cloud

Laravel Cloud runs Relaticle from this repository rather than from
`compose.yml`. The Dockerfile, the five services it composes, and `bin/` are
not used. Each one maps onto a Cloud resource instead, and the sections below
go through that mapping, the environment variables it needs, and the two things
it does not cover.

Nothing here changes how Relaticle runs anywhere else. Every variable this
document introduces defaults to the single-server behaviour when it is unset.

## What replaces what

| `compose.yml` | Laravel Cloud |
| --- | --- |
| `app` (nginx + php-fpm, :8080) | Application compute. No web server config. |
| `postgres` | Serverless Postgres. Pick the newest version offered; Relaticle needs 17 or later. |
| `redis` | Laravel Valkey, which speaks the Redis protocol. |
| `horizon` | A worker cluster running `php artisan horizon`. |
| `scheduler` | Scheduled tasks, enabled per environment. |
| `storage` volume | Two object storage buckets. See [Uploads](#uploads). |
| `AUTORUN_LARAVEL_MIGRATION` | A deploy command. See [Commands](#commands). |
| Reverse proxy TLS | Automatic, and required: passkeys do not work without it. |

## Minimum infrastructure

Six resources, and one of them is optional. The sizes below are the smallest
that were actually deployed and exercised, not an estimate.

| Resource | Minimum | Notes |
| --- | --- | --- |
| Application compute | `flex-512mb`, 1 replica | Scheduler enabled on this instance. |
| Serverless Postgres | dev preset, 0.25 CU | Version 17 or later. Suspends after 300s idle. |
| Laravel Valkey | `valkey-flex-250mb` | Eviction policy `noeviction`. See below. |
| Private bucket | n/a | Attached as the environment's default disk. |
| Public bucket | n/a | Separate bucket: one bucket carries one visibility. |
| A queue worker | one of two shapes | Horizon on a worker, or a managed queue. |
| WebSockets | optional | Only the chat assistant's live streaming needs it. |

The self-hosting guide asks for 2 GB of RAM, but that figure covers the whole
`compose.yml` stack. On Cloud the database and cache are separate resources, so
the application container itself runs in 512 MiB. Node, PHP and the package
manager are detected from the repository: PHP 8.5, Node 24 and pnpm. None of
them need configuration.

`noeviction` matters when Valkey is also backing the queue: any other policy
lets Redis discard keys under memory pressure, and queued jobs are keys.

**WebSockets are the one always-on resource.** Everything else here scales to
zero: compute hibernates, Postgres suspends, and a Flex queue worker idles at
zero. A Reverb cluster is provisioned capacity billed on concurrent connections,
and it keeps accruing charges until the cluster is deleted. Detaching it is not
enough. Broadcasting is used by seven events, all of them in `packages/Chat`, so
an environment that does not need live assistant streaming can leave it out
entirely and set `BROADCAST_CONNECTION=log`.

## Commands

Build:

```sh
composer install --no-dev --prefer-dist --optimize-autoloader
pnpm install --frozen-lockfile
pnpm run build
```

Deploy:

```sh
php artisan migrate --force
php artisan optimize
```

`php artisan optimize` covers the config, route, view, and event caches that
`AUTORUN_LARAVEL_*` handles in the container. `storage:link` has no equivalent
here and is not needed, because nothing is served out of `storage/app/public`
once the buckets below are attached.

## Uploads

Cloud's application filesystem is ephemeral. It is discarded on every deploy,
and two replicas do not share one. Relaticle writes uploads in two classes, and
they cannot share a bucket: an S3-compatible bucket carries a single visibility
for every object in it.

So attach two buckets.

Attaching a bucket asks for a disk name, and Cloud then builds that disk itself,
credentials and all, and adds it to `config/filesystems.php` at runtime. So both
buckets are wired by attaching them and naming the two disks. No `AWS_*`
variables are set by hand.

**A private bucket, attached as the environment's default disk, disk name
`private`.** It backs pending uploads and media library files: everything that
must not be reachable by URL. Signed `/media/{uuid}` routes serve these to the
browser. Livewire's temporary uploads follow it too, because
`LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK` is unset and Livewire then falls back to
the default disk. Leave it unset.

**A public bucket, disk name `public`.** It backs workspace and company logos
and legacy rich-editor images, all of which render through a plain `<img src>`.
The name deliberately shadows Laravel's local `public` disk, which is what the
defaults in `config/relaticle.php` already point at, so nothing else has to
change.

```ini
FILESYSTEM_PRIVATE_DISK=private
MEDIA_DISK=private

FILESYSTEM_PUBLIC_DISK=public
FILAMENT_FILESYSTEM_DISK=public
```

Do not set `AWS_ACCESS_KEY_ID`, `AWS_BUCKET`, `AWS_ENDPOINT` or their
`AWS_PUBLIC_*` counterparts on Cloud. Variables you set yourself take precedence
over the ones Cloud injects, so they override a working disk with a broken one.
The trap is that a bucket's real name is the `fls-…` identifier, not the
display name you typed when creating it. `AWS_BUCKET=my-bucket-name` therefore
points at a bucket that does not exist, and R2 answers `AccessDenied` rather
than a missing bucket. Confirm a disk end to end instead of trusting a write to
report success:

```php
Storage::disk('private')->put('smoke.txt', 'x');   // silent on failure
```

Cloud's disks set `'throw' => false`, so a failed write returns `false` rather
than raising. Read the file back, or build the disk with `'throw' => true`, when
checking whether storage actually works.

Add the environment's domain to the public bucket's allowed origins if you also
upload from a local machine; Cloud adds its own domains for you.

## Queues, cache, and sessions

Horizon only supervises Redis queues, so with Horizon `QUEUE_CONNECTION` must be
`redis` against the attached Valkey cache. This mirrors what `compose.yml`
already overrides. The `database` default in `.env.example` is for a bare local
setup, not for a deployment that runs a worker. With managed queues, Cloud sets
`QUEUE_CONNECTION` itself; leave it alone.

```ini
DB_CONNECTION=pgsql
REDIS_CLIENT=phpredis

QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis
```

`DB_*` and `REDIS_*` connection details are injected when the database and cache
are attached. If connection counts become a problem, point `DB_HOST` at the
cluster's pgbouncer endpoint, which is the hostname with `-pooler` appended to
its first segment.

There are two ways to actually run the jobs, and they are mutually exclusive.

### Horizon on a worker

The arrangement Relaticle is built for. Run the worker's process as
`php artisan horizon`, not `queue:work`; `config/horizon.php` owns the
supervisors, and all three queues keep their own memory ceilings, timeouts and
autoscaling. Set `HORIZON_ADMIN_EMAILS` to reach the dashboard, which denies
everyone by default outside local. Leave the `RELATICLE_QUEUE_*` variables
alone.

The catch is scale-to-zero: the App cluster stops when the sleep timeout
elapses even if a job is still running, so an environment that hibernates will
cut long jobs short.

### Managed queues

Cloud supervises the workers itself, and they scale independently of the App
cluster, so background work survives the application sleeping. Horizon cannot
be used at all here: it does not support managed queues, and neither do
`queue:failed`, `queue:retry` or `queue:clear`. Failed jobs are handled from the
Queues dashboard.

Creating any managed queue makes Cloud set `QUEUE_CONNECTION=cloud`, and from
that moment every job dispatched without an explicit connection goes to it. A
job pinned to a queue that has no managed queue behind it is accepted and then
never processed, with nothing raised. The pins therefore have to come off at the
same time:

```ini
RELATICLE_QUEUE_HORIZON=false
RELATICLE_QUEUE_IMPORTS=
RELATICLE_QUEUE_CHAT=
RELATICLE_QUEUE_CHAT_CONNECTION=
```

Empty values drop the pin and the job falls back to the default queue.
`RELATICLE_QUEUE_HORIZON=false` also removes the Horizon health check, which
would otherwise fail against a Horizon that is not running.

Two limits decide whether this is viable for a given plan. The Starter plan
allows **one** managed queue per environment, which is what forces the pins off
rather than one managed queue per name. And Flex workers cap a job at **90
seconds**, while `config/horizon.php` gives imports a 300-second timeout. Large
imports therefore need a Pro queue, which the Growth plan and above offer. On
Starter, expect long imports to be cut off.

## Trusted proxies

Nothing to configure, but worth understanding, because getting it wrong is
invisible until you try to log in.

Cloud terminates TLS on a proxy in front of the application, well outside the
private ranges a same-network reverse proxy arrives from. The framework already
knows this: `TrustProxies` trusts the calling IP when it finds `LARAVEL_CLOUD`
set in the environment. That fallback applies only when the application has not
named its own proxies, because an explicit list takes precedence over it.

So `bootstrap/app.php` passes the list only when it is not running on Cloud.
Naming the private ranges there anyway would suppress the detection,
`X-Forwarded-Proto` would be ignored, and `$request->isSecure()` would stay
false. Assets would still look right, because those come from `APP_URL`. What
breaks is Livewire's endpoint, which is then generated as `http` and blocked by
the browser as mixed content: the panel renders and no request it makes ever
completes. WebAuthn fails the same origin check, so passkeys stop working too.

Trusting the calling IP means believing whatever `X-Forwarded-For` arrives,
which is safe here only because the container cannot be reached except through
Cloud's proxy, and that proxy sets the header itself. On a platform where the
application is reachable directly, the same trust would let a client claim any
address it likes, and Relaticle's pre-authentication throttling is keyed on the
client address. List the proxies explicitly on such a platform.

## Broadcasting

Relaticle broadcasts over Reverb, which needs a WebSockets cluster of its own on
Cloud. Until one is attached, set `BROADCAST_CONNECTION=log`.

Attaching a cluster injects every `REVERB_*` variable, including the
`VITE_REVERB_*` ones the browser client needs, so nothing is set by hand. Those
are read at build time, which means the environment has to be redeployed after
attaching before the front end knows about it.

`BROADCAST_CONNECTION=reverb` without a cluster behind it does not degrade
quietly. It fails the **build**: `php artisan event:clear` boots the
application and reaches `Broadcast::channel()` in
`packages/Chat/routes/channels.php`. Deleting a cluster therefore means setting
`BROADCAST_CONNECTION` back to `log` in the same breath, or the next deploy
cannot complete. Detaching the WebSocket application on its own is not the
problem; the stale connection name is.

Scale-to-zero and long-lived WebSocket connections also work against each
other: an environment that sleeps drops them.

## Known limitations

**Chat CSV attachments need a local path.** `AgentConversation` pins its
attachment collection to the `local` disk, because `ChatAttachment` reaches for
`Media::getPath()` and the CSV readers behind it take a filesystem path rather
than a stream. That file is written by the web request and read by a queue
worker on a different instance, which cannot see it.
Either keep this off, or keep the environment on a single replica and accept
that a deploy discards anything still in flight.

**`media:backfill-rich-editor-attachments` migrates from where it runs.** The
command rewrites legacy rich-editor image references by reading them off the
public disk. Pointing `FILESYSTEM_PUBLIC_DISK` at a bucket that never held those
legacy files finds nothing to migrate. Run it before the move, or upload the old
`storage/app/public` contents into the public bucket first.

## First deploy

1. Create the application from this repository and pick the branch. PHP, Node
   and the package manager are detected; the build command is pre-filled.
2. Attach Serverless Postgres and Valkey, and attach both buckets, naming their
   disks `private` and `public`. The private one is the default disk.
3. Set the variables above, plus `APP_ENV=production`,
   `APP_DEBUG=false` and `APP_URL`. Cloud generates `APP_KEY`. Set no `AWS_*`
   variables.
4. Set the deploy command; the build command is already correct.
5. Enable the scheduler on the application instance, and add either a worker
   running `php artisan horizon` or a managed queue, never both.
6. Deploy. Migrations run as a deploy command, so the first deploy builds the
   schema.
7. Create the first administrator with `php artisan sysadmin:create`, which
   reaches the `/sysadmin` panel. `make:filament-user` does not: that panel runs
   on its own guard and model.

Confirm storage before trusting it. These disks carry `'throw' => false`, so a
failed write returns `false` silently. Read the file back:

```php
Storage::disk('private')->put('smoke.txt', 'x');
Storage::disk('private')->get('smoke.txt');   // must return 'x'
```
