<p align="center"><img src="art/icon.png" width="128" alt="slate icon"></p>

# Slate

Slate turns images, audio, fonts and video you already own into a finished MP4, on servers you control.

## What Slate does

You send Slate a **recipe**: a short JSON description of the video you want. Slate renders it with
`ffmpeg` on a queue and gives you back one signed download URL.

Two recipes exist today:

- **`delivery`** — an ordered list of pages, each an image plus an audio track, with optional
  per-word captions and cross-fades between pages.
- **`highlights`** — one video source plus a list of clip ranges, cut and joined into a single video.

You never send `ffmpeg` arguments. Slate accepts named operations with typed, validated parameters
and compiles the command line itself. That is a security boundary, not a convenience: `ffmpeg` can
read and write files and URLs, so a pass-through API would let a caller read your disk.

**What it replaces:** a per-minute hosted video API, or an always-on transcode server sized for your
busiest hour. Slate runs on Laravel Cloud, renders on a managed queue that scales to zero between
jobs, and ships its own pinned `ffmpeg` binary inside the deploy artifact — so there is no
per-minute meter and nothing to keep warm.

## The easy way: Scalpels

Slate is MIT licensed and you can run it yourself, forever. If you would rather not spend the
afternoon, [Scalpels](https://scalpels.app) sets it up and runs it for you — provisioning, the
Laravel Cloud resources, deploys and upgrades — on infrastructure that stays yours.

Scalpels sells the setup, not the software. Everything below is the do-it-yourself path, and it stays
complete and supported.

## Run it yourself

### Prerequisites

| You need | Why |
| --- | --- |
| PHP 8.3 or newer | `composer.json` requires `^8.3`. CI runs 8.5. |
| Composer 2 | Dependency install. |
| Docker | To run the object-storage and `ffmpeg` test lanes locally. |
| An S3-compatible bucket | Slate stores every source and result in object storage. Its default filesystem disk must be S3-backed. |
| A Laravel Cloud account | For the deploy steps. Local development works without one. |

The rendering binary is a **Linux arm64** build. Steps 1–4 run on any platform; step 5 needs Linux
arm64, so on macOS or Windows you run it inside a container.

### 1. Install dependencies

```bash
git clone https://github.com/artisan-build/slate.git
cd slate
composer install
```

You should see Composer finish with no errors and a `vendor/` directory appear.

### 2. Create your environment file

```bash
cp .env.example .env
php artisan key:generate
```

`key:generate` prints `Application key set successfully.` and writes `APP_KEY` into `.env`.

### 3. Create the database

The example environment uses SQLite, which needs no server.

```bash
php artisan migrate
```

You should see a long list of migrations, each ending in `DONE`. These create Slate's own `runs`,
`sources` and `ingests` tables plus the Built for Cloud tables that hold users and credentials.

### 4. Run the tests

```bash
php artisan test
```

Everything should pass — 531 tests at the time of writing — with around twenty skipped. The skipped
ones are the lanes that need MinIO or the `ffmpeg` binary; the rest of this step and step 5 turn
those on.

To run the object-storage lane, start MinIO in one terminal:

```bash
docker run --rm --publish 19000:9000 \
    --env MINIO_ROOT_USER=slate-minio \
    --env MINIO_ROOT_PASSWORD=slate-minio-secret \
    minio/minio:latest server /data
```

and require that lane in another:

```bash
SLATE_REQUIRE_MINIO=1 php artisan test --group=minio
```

Four tests run, and they create the bucket they need themselves.

### 5. Install the `ffmpeg` runtime

Slate does not use whatever `ffmpeg` is on your machine. `slate:provision-ffmpeg` downloads one
pinned LGPL build from [BtbN/FFmpeg-Builds](https://github.com/BtbN/FFmpeg-Builds), checks it against
the release's published SHA-256, extracts it, deletes the parts Slate never uses, and installs
`ffmpeg` and `ffprobe` into `runtime/ffmpeg/bin` inside the project. That directory is gitignored —
it is rebuilt on every deploy, never committed.

The command needs `xz` and `tar` present, and the pinned build only runs on Linux arm64. On macOS,
run it and the render tests in a container:

```bash
docker run --rm --platform linux/arm64 --user root -e SLATE_REQUIRE_FFMPEG=1 \
    -v "$PWD:/app" -w /app serversideup/php:8.4-cli \
    sh -c 'apt-get update -qq && apt-get install -y -qq fontconfig fonts-dejavu-core; \
           php artisan slate:provision-ffmpeg --force; \
           vendor/bin/pest --group=ffmpeg-render'
```

You should see the install finish, then the render tests pass. The install is about 180 MB of
`ffmpeg` and `ffprobe`.

Two things about that container command are deliberate. `--force` reinstalls even when the
provisioner thinks the binary is already there, because macOS bind mounts report deleted files as
still present. Installing the fonts packages matters because Slate refuses to draw captions with a
substituted system font — it fails the render instead of shipping the wrong typeface.

### 6. Deploy to Laravel Cloud

**The one rule that breaks everything if you get it wrong: never set an environment variable for a
resource Laravel Cloud provisions for you.** When you attach a database, cache, queue or bucket,
Cloud writes all of its settings — the credentials *and* the selectors like `DB_CONNECTION`,
`QUEUE_CONNECTION`, `CACHE_STORE` and `FILESYSTEM_DISK` — into a managed environment file that your
app reads. Any value you set yourself shadows Cloud's and breaks that resource. Set only app-specific
variables, such as the ones in the table below.

1. **Create the application.** Run `cloud ship` from the project directory and follow the prompts.
   Let it create and attach the database — that is the reliable path. Afterwards run
   `cloud repo:config` so `.cloud/config.json` records which Cloud application this repo deploys to.
   Commit that file; it holds two opaque identifiers and no credentials.
2. **Create a bucket and attach it.** Create it with `cloud bucket:create` (private visibility, a
   read/write key). Attaching a bucket has no CLI flag today — do it in the Cloud dashboard, under
   the environment's storage settings. Once attached, Cloud wires the default disk to the bucket.
3. **Create a managed queue.** `cloud managed-queue:create` then `cloud managed-queue:set-default`.
   Slate renders on two queues, `default` and `ingest`; both must be worked.
4. **Enable the scheduler.** Slate runs `slate:prune-ingests` hourly to clean up storage left behind
   by failed and expired uploads. Without the scheduler, that storage is never reclaimed.
5. **Add `php artisan slate:provision-ffmpeg` to the build command** so every deploy vendors the
   binary into the artifact. Make sure the build image has `xz` available.
6. **Set app variables and deploy.** Set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, and
   `SLATE_INSTALLATION_SUBJECT_REF` (see below). Then `cloud deploy`, and run
   `cloud command:run <env> --cmd "php artisan migrate --force"`.

### 7. First use

Create the first account — an Owner — so you can sign in to the web console at `/bfc/login`:

```bash
php artisan create-admin --email=you@example.com --password=... --name="Your Name" --local
```

> **`--local` is not optional here.** Without it, this command asks which **Laravel Cloud
> environment** to create the account in and can run against your production app. The
> `bfc:credential:*` commands are stricter and simply refuse without `--local`. Whenever you mean
> "this machine", pass `--local`.

Now mint the bearer credential the API needs. A credential carries a **purpose** — the one operation
it is allowed to perform. Slate has exactly one, `slate.sources`, which maps to the protocol purpose
`consumption`. Nothing else opens the API.

The second argument must be exactly the value you put in `SLATE_INSTALLATION_SUBJECT_REF`, which for
a fresh `.env` is `local-only-change-me`:

```bash
php artisan bfc:credential:mint installation local-only-change-me \
    --kind=bearer --purpose=consumption --name="my client" --local
```

The token is printed **once** and starts with `slate_`. Save it; it cannot be read back.

Check it works. Start the app in one terminal:

```bash
composer dev
```

and in another, with `SLATE_TOKEN` set to the token you just saved:

```bash
curl -s -o /dev/null -w '%{http_code}\n' \
    http://localhost:8000/api/v1/runs/41414141-4141-4141-8141-414141414141

curl -s -H "Authorization: Bearer $SLATE_TOKEN" -H 'Accept: application/json' \
    http://localhost:8000/api/v1/runs/41414141-4141-4141-8141-414141414141
```

The first returns `401`. The second returns
`{"message":"The run was not found.","reason":"run_not_found"}` with status `404` — which is the
answer you want, because it proves the credential was accepted and the run id simply does not exist
yet.

## Configuration

Every value below has a working default. Set only what you need to change.

| Variable | Default | What it controls |
| --- | --- | --- |
| `SLATE_INSTALLATION_SUBJECT_REF` | unset (`.env.example` ships `local-only-change-me`) | The one installation subject allowed to call the API with an installation credential. Leave it unset and only user-bound credentials work — there is no shared fallback. |
| `SLATE_FFMPEG_RELEASE_TAG` | `autobuild-2026-08-31-13-27` | Which BtbN release to download. |
| `SLATE_FFMPEG_ASSET` | `ffmpeg-n9.0.1-11-ge47273f4d9-linuxarm64-lgpl-9.0.tar.xz` | The archive filename inside that release. |
| `SLATE_FFMPEG_RUNTIME_PATH` | `runtime/` in the project root | Where `ffmpeg` is installed. Keep it inside the project — only the project directory survives a deploy. |
| `SLATE_FFMPEG_FONT_PATH` | `resources/fonts/NotoSans[wdth,wght].ttf` | The font captions are drawn with. |
| `SLATE_ALLOW_HOST_LOCAL_FANOUT` | `false` | Allows parallel rendering against a non-S3 disk. Single-host local development only; leave it off everywhere else. |

The rest of `config/slate.php` — input limits, queue timing, worker ceilings, measured render rates —
is tuned against Laravel Cloud's runtime and is not env-configurable on purpose. Read it before
changing it; the comments record what each number was measured against.

Two environment variables exist only for tests: `SLATE_REQUIRE_MINIO=1` and `SLATE_REQUIRE_FFMPEG=1`
turn a lane's "skip" into a failure, so CI cannot silently pass without them.

## Using Slate

### The HTTP API

Five endpoints, all under `/api/v1`, all requiring the bearer credential from step 7:

| Method | Path | Purpose |
| --- | --- | --- |
| `POST` | `/api/v1/sources` | Register a source, by direct upload or by HTTPS URL. |
| `GET` | `/api/v1/sources/{source}` | Check whether a source is `ready`. |
| `POST` | `/api/v1/runs` | Submit a recipe. |
| `GET` | `/api/v1/runs/{run}` | Check run status. |
| `GET` | `/api/v1/runs/{run}/result` | Get the signed download URL. |

A complete run is: upload each source, wait for `ready`, submit a recipe naming those source ids,
then poll the run or let Slate call your webhook. Source and run ids are UUIDs.

Ask for an upload slot:

```bash
curl -X POST https://your-slate-host/api/v1/sources \
    -H "Authorization: Bearer $SLATE_TOKEN" \
    -H 'Content-Type: application/json' \
    -d '{"upload": {"content_type": "image/png", "size": 123456}}'
```

You get `201` and a short-lived, single-use upload URL:

```json
{
  "id": "c12a53df-9ec6-48e9-8a4a-1f75a82cee80",
  "put_url": "https://object-storage.example/...?X-Amz-Signature=...",
  "headers": {"Host": ["object-storage.example"], "If-None-Match": ["*"]},
  "expires_at": "2026-09-22T22:46:09.061827Z",
  "object_key": "slate-sources/c12a53df-9ec6-48e9-8a4a-1f75a82cee80/source"
}
```

Then `PUT` your bytes to `put_url` before `expires_at`, **sending every header in `headers`**:

```bash
curl -X PUT "$PUT_URL" -H 'If-None-Match: *' --data-binary @page-one.png
```

Those headers are part of the signature. Drop `If-None-Match: *` and object storage rejects the
request with `400`; it is also what makes the slot single-use, so an uploaded object can never be
replaced. `object_key` is informational — recipes reference the source `id`, never a raw object key.

Poll the source until it reports `ready`:

```bash
curl -H "Authorization: Bearer $SLATE_TOKEN" \
    https://your-slate-host/api/v1/sources/c12a53df-9ec6-48e9-8a4a-1f75a82cee80
```

```json
{"id": "c12a53df-...", "status": "ingesting", "rejection_reason": null, "bytes": 70, "content_type": "image/png"}
```

`status` moves `pending` → `ingesting` → `ready`, or lands on `failed` or `rejected`. A rejected
source names the reason, such as `unsupported_container` or `resolution_exceeded`. Nothing advances
unless a queue worker is running.

Recipe formats, every error `reason`, the webhook signature scheme and the full list of rejection
reasons are in [`docs/API.md`](docs/API.md). Input limits and the reasoning behind them are in
[`docs/INPUT-RISKS.md`](docs/INPUT-RISKS.md).

### The web console

Slate's UI comes from the Built for Cloud package. `/` is the landing page and `/bfc/login` is the
sign-in form; once signed in, `/bfc/members`, `/bfc/me/sessions` and `/bfc/installation/credentials`
manage members, sessions and credentials. The same credential administration is available from the
command line — `bfc:credential:list`, `:rotate`, `:revoke` — always with `--local`.

## Troubleshooting

**`Slate ingest requires an S3-backed default filesystem.`** The default disk is not S3. Locally,
point `FILESYSTEM_DISK` at the `s3` disk and fill in the `AWS_*` values, or run MinIO. On Laravel
Cloud, this means the bucket is not attached to the environment — attach it in the dashboard, and do
not set `FILESYSTEM_DISK` or `AWS_*` yourself.

**``Missing required `xz` executable.``** `slate:provision-ffmpeg` cannot unpack the archive. Install
`xz-utils` in your build image.

**`exec: not found` when rendering, right after provisioning.** You are on macOS with a bind-mounted
container. PHP's file checks see stale results through the mount and the provisioner skips the
install. Re-run it with `--force`.

**`php artisan test` starts failing 13 tests after step 5, on macOS.** The failures say `cannot
execute binary file`. Provisioning left a Linux arm64 `ffmpeg` in `runtime/`, so the render tests
stop skipping and try to run it on your Mac. Either run the whole suite in the container, or
`rm -rf runtime` to go back to skipping that group on the host.

**Captions fail to render instead of using another font.** That is deliberate. Slate isolates
`fontconfig` so a missing or unloadable font file fails loudly rather than silently substituting
whatever font the host happens to have. Check `SLATE_FFMPEG_FONT_PATH` points at a real file.

**A `502` from the API.** Laravel Cloud returns one while the instance wakes from hibernation. Retry
with backoff. If you are retrying a run submission, reuse the same run `id` — Slate answers a
duplicate id with `409 run_id_conflict` and starts no second render.

**A run stays `queued`.** Nothing is working the queues. Slate uses two: `default` for rendering and
`ingest` for pulling remote sources. Locally, `php artisan queue:work --queue=default,ingest`.

## License

Slate is open-source software licensed under the [MIT license](https://opensource.org/licenses/MIT).
