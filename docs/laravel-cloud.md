# Deploying on Laravel Cloud

Laravel Cloud runs Relaticle from this repository rather than from `compose.yml`.
The Dockerfile, the five services it composes, and `bin/` are not used. Each one
maps onto a Cloud resource instead, and the sections below go through that
mapping, the environment variables it needs, and the two things it does not
cover.

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

**A private bucket, as the environment's default disk, with disk name `s3`.**
Cloud injects `FILESYSTEM_DISK` and the `AWS_*` credentials for whichever bucket
is the default, and the `s3` disk in `config/filesystems.php` already reads them.
This backs pending uploads and media library files — everything that must not be
reachable by URL. Signed `/media/{uuid}` routes serve these to the browser.
Livewire's temporary uploads follow it too, because
`LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK` is unset and Livewire then falls back to
the default disk. Leave it unset.

Leave `AWS_DEFAULT_REGION` unset as well. Cloud injects `AWS_REGION` for the
attached bucket, and the `s3` disk falls back to it; a value carried over from
`.env.example` would take precedence over the right one.

**A public bucket, with any disk name.** Its credentials are not injected, so
copy them from the bucket's settings page into the environment's own variables
under the `AWS_PUBLIC_*` names below. `AWS_PUBLIC_URL` is the bucket's public
base URL, which Cloud shows on the same page but never injects. This backs
workspace and company logos and legacy rich-editor images, all of which render
through a plain `<img src>`.

```ini
# Injected when the private bucket is attached as the default disk.
# FILESYSTEM_DISK=s3
# AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY / AWS_BUCKET / ...

FILESYSTEM_PRIVATE_DISK=s3
MEDIA_DISK=s3

FILESYSTEM_PUBLIC_DISK=s3_public
FILAMENT_FILESYSTEM_DISK=s3_public

# Copied by hand from the public bucket's settings page.
AWS_PUBLIC_ACCESS_KEY_ID=
AWS_PUBLIC_SECRET_ACCESS_KEY=
AWS_PUBLIC_DEFAULT_REGION=auto
AWS_PUBLIC_BUCKET=
AWS_PUBLIC_URL=
AWS_PUBLIC_ENDPOINT=
```

Add the environment's domain to the public bucket's allowed origins if you also
upload from a local machine; Cloud adds its own domains for you.

## Queues, cache, and sessions

Horizon only supervises Redis queues, so `QUEUE_CONNECTION` must be `redis`
against the attached Valkey cache. This mirrors what `compose.yml` already
overrides — the `database` default in `.env.example` is for a bare local setup,
not for a deployment that runs Horizon.

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

Run the worker cluster's process as `php artisan horizon`, not `queue:work`;
`config/horizon.php` owns the queue and balance settings. Set
`HORIZON_ADMIN_EMAILS` to reach the dashboard, since it denies everyone by
default outside local.

## Broadcasting

Relaticle broadcasts over Reverb, which needs a WebSockets cluster of its own on
Cloud. Until one is attached, set `BROADCAST_CONNECTION=log`. Leaving it on
`reverb` with no reachable host makes queued jobs throw when they broadcast.

With a cluster attached, set `REVERB_*` from its credentials and
`REVERB_SCHEME=https`. Note that scale-to-zero and long-lived WebSocket
connections work against each other: an environment that sleeps drops them.

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

1. Create the application from this repository and pick the branch.
2. Attach Serverless Postgres, Valkey, and the two buckets.
3. Set the variables above. Cloud generates `APP_KEY`; set `APP_ENV=production`,
   `APP_DEBUG=false`, and `APP_URL` to the environment's URL.
4. Set the build and deploy commands.
5. Enable scheduled tasks and add the Horizon worker.
6. Deploy. Migrations run as a deploy command, so the first deploy builds the
   schema.
