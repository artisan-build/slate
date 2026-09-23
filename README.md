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

**What it replaces:** a hosted video API that bills per minute rendered, or a transcode server you
keep running and sized for your busiest hour. Slate ships its own pinned `ffmpeg` inside the deploy
artifact and renders on a Laravel Cloud managed queue, so no third party meters your output. You
still pay your own infrastructure bill — Laravel Cloud compute, the queue, the database and the
object storage the sources and results live in.

## The easy way: Scalpels

Slate is MIT licensed and you can run it yourself. If you would rather not spend the afternoon,
[Scalpels](https://scalpels.app) sets it up and runs it for you — provisioning, the Laravel Cloud
resources, deploys and upgrades — on infrastructure that stays yours.

Scalpels sells the setup, not the software. Everything below is the do-it-yourself path.

---

# Run it locally

By the end of this section you will have rendered a real MP4 on your own machine.

## Prerequisites

| You need | Why |
| --- | --- |
| **64-bit PHP 8.3–8.x with the `gmp` extension** | `composer.json` requires `^8.3`, which means 8.3 up to but not including 9.0. The locked Built for Cloud dependency chain adds two hard requirements: `paragonie/paseto` requires `ext-gmp`, and `paragonie/sodium_compat` requires `php-64bit`. Both are `require` entries, not suggestions — `composer install` fails without them. CI runs 8.5. |
| Composer 2 | Dependency install. |
| Git, and access to the repository | Slate's repository is **private** today, so an anonymous `git clone` fails with a 404. You need a GitHub account that has been granted access, and either an SSH key or `gh auth login`. |
| Docker | Object storage and the `ffmpeg` runtime both run in containers below. |

Check the PHP requirements before you install anything:

```bash
php -r 'printf("%s | %d-bit | gmp=%s\n", PHP_VERSION, PHP_INT_SIZE * 8, extension_loaded("gmp") ? "yes" : "NO");'
```

You want something like `8.4.23 | 64-bit | gmp=yes`. If it says `32-bit` or `gmp=NO`, fix that first —
`composer install` will fail otherwise, and the error will not mention this README.

## 1. Get the code and install dependencies

```bash
git clone https://github.com/artisan-build/slate.git
cd slate
composer install
```

Composer finishes with no errors and a `vendor/` directory appears.

## 2. Create your environment file

```bash
cp .env.example .env
php artisan key:generate
```

`key:generate` prints `Application key set successfully.` and writes `APP_KEY` into `.env`.

## 3. Create the database

The example environment uses SQLite, which needs no server.

```bash
php artisan migrate
```

A long list of migrations each ends in `DONE`. These create Slate's own `runs`, `sources` and
`ingests` tables plus the Built for Cloud tables that hold users and credentials.

## 4. Run the tests

```bash
php artisan test
```

Everything passes — 531 tests at the time of writing — with around twenty skipped. The skipped ones
need object storage or the `ffmpeg` binary, which the next two steps set up.

## 5. Start object storage and point Slate at it

**Slate cannot serve a single API request without S3-compatible object storage.** Every source and
every result lives there; the default filesystem disk must be S3-backed or the first real request
fails. `.env.example` ships `FILESYSTEM_DISK=local`, so you must change it.

MinIO is an S3-compatible server that runs in one container. Put it on a Docker network so the
`ffmpeg` container in the next step can reach it by name:

```bash
docker network create slate-local

docker run -d --name slate-minio --network slate-local --network-alias minio \
    --publish 19000:9000 \
    --env MINIO_ROOT_USER=slate-minio \
    --env MINIO_ROOT_PASSWORD=slate-minio-secret \
    minio/minio:latest server /data
```

Now add these lines to `.env`, replacing `FILESYSTEM_DISK=local` and `QUEUE_CONNECTION=database`
where they already exist:

```ini
FILESYSTEM_DISK=s3
QUEUE_CONNECTION=database

AWS_ACCESS_KEY_ID=slate-minio
AWS_SECRET_ACCESS_KEY=slate-minio-secret
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=slate
AWS_USE_PATH_STYLE_ENDPOINT=true
AWS_ENDPOINT=http://minio:9000
```

> **About `AWS_ENDPOINT`.** Slate signs upload and download URLs using this address, and both the web
> app and the queue worker must reach it. The walkthrough below runs both inside a container on the
> `slate-local` network, so `http://minio:9000` is the address that works for both. If you are on
> **Linux arm64** you can skip the container entirely and run everything on the host — in that case
> use `http://127.0.0.1:19000` instead.

These are local development credentials for a throwaway container. Never reuse them anywhere else.

## 6. Install the `ffmpeg` runtime

Slate does not use whatever `ffmpeg` is on your machine. `slate:provision-ffmpeg` downloads one
pinned LGPL build from [BtbN/FFmpeg-Builds](https://github.com/BtbN/FFmpeg-Builds), checks it against
the release's published SHA-256, extracts it, deletes the parts Slate never uses, and installs
`ffmpeg` and `ffprobe` into `runtime/ffmpeg/bin` inside the project. That directory is gitignored —
it is rebuilt on every deploy, never committed.

The pinned build is **Linux arm64**, and the command needs `xz` and `tar`. Open a shell in a
container that has all three, on the same network as MinIO:

```bash
docker run --rm -it --platform linux/arm64 --user root \
    --network slate-local --publish 8000:8000 \
    --volume "$PWD:/app" --workdir /app \
    serversideup/php:8.4-cli bash
```

Everything from here to the end of step 8 runs **inside that shell**. First install the font
packages and the runtime:

```bash
apt-get update -qq && apt-get install -y -qq fontconfig fonts-dejavu-core curl xz-utils
php artisan slate:provision-ffmpeg --force
```

It prints the paths to `ffmpeg` and `ffprobe`. The install is about 180 MB.

Two details are deliberate. `--force` reinstalls even when the provisioner thinks the binary is
already there, because macOS bind mounts report deleted files as still present. The font packages
matter because Slate refuses to draw captions with a substituted system font — it fails the render
rather than shipping the wrong typeface.

## 7. Create your first account and API credential

Still inside the container. Create the bucket and the first account:

```bash
php artisan tinker --execute '
$c = new Aws\S3\S3Client(["version"=>"latest","region"=>"us-east-1","endpoint"=>"http://minio:9000",
  "use_path_style_endpoint"=>true,"credentials"=>["key"=>"slate-minio","secret"=>"slate-minio-secret"]]);
$c->createBucket(["Bucket"=>"slate"]);'

php artisan create-admin --email=you@example.com --name="Your Name" --local
```

Leave `--password` off. The command then asks for the password twice at a hidden prompt, which keeps
it out of your shell history and out of the process list. It must be **at least 8 characters**. The
first account created becomes the Owner.

> **`--local` is not optional.** Without it, `create-admin` asks which **Laravel Cloud environment**
> to create the account in, and can write to your production app. The `bfc:credential:*` commands are
> stricter and refuse outright without `--local`. Whenever you mean "this machine", pass `--local`.

Now mint the bearer credential the API needs.

A credential carries a **purpose** — the single operation it is allowed to perform. Slate defines
exactly one, `slate.sources`, which maps to the protocol purpose `consumption`. Nothing else opens
the API.

It is also bound to an **installation subject reference**: a name you choose for this particular
installation of Slate. Slate only accepts an installation credential whose reference matches
`SLATE_INSTALLATION_SUBJECT_REF` exactly. It is an identifier, not a secret — on its own it grants
nothing, and a caller still needs a credential minted against it. Pick something stable and
descriptive, such as `acme-slate-production`, and keep it: changing it stops every credential minted
against the old value from working. A fresh `.env` ships the placeholder `local-only-change-me`,
which is the value to use locally.

```bash
export SLATE_TOKEN=$(php artisan bfc:credential:mint installation local-only-change-me \
    --kind=bearer --purpose=consumption --name="local walkthrough" --local \
    | grep -oE 'slate_[0-9a-f]+')

echo "${SLATE_TOKEN:0:6}… (${#SLATE_TOKEN} characters)"
```

Run without the pipe and the token is printed once, starting with `slate_`; it cannot be read back
afterwards. The command above captures it into `$SLATE_TOKEN` for the next step.

## 8. Render your first video

Still inside the container. Start the web app and a queue worker in the background:

```bash
php artisan serve --host=0.0.0.0 --port=8000 > /tmp/serve.log 2>&1 &
php artisan queue:work --queue=default,ingest --tries=1 --sleep=1 > /tmp/worker.log 2>&1 &
```

Slate uses two queues — `default` renders, `ingest` pulls sources — and **nothing progresses unless
both are being worked**. Because you published port 8000, you can now also open
<http://localhost:8000/bfc/login> in your own browser and sign in with the account from step 7.

You need two images and one audio file. Bring your own, or make throwaway ones with the `ffmpeg` you
just installed:

```bash
FF=/app/runtime/ffmpeg/bin/ffmpeg
mkdir -p /tmp/media
$FF -y -loglevel error -f lavfi -i "color=c=red:s=640x480"  -frames:v 1 /tmp/media/page-1.png
$FF -y -loglevel error -f lavfi -i "color=c=blue:s=640x480" -frames:v 1 /tmp/media/page-2.png
$FF -y -loglevel error -f lavfi -i "anullsrc=r=44100:cl=mono" -t 3 -c:a libmp3lame -b:a 192k /tmp/media/narration.mp3
```

Upload each one. Registering a source returns a single-use signed `PUT` URL; you send the bytes
straight to object storage, and Slate never proxies them:

```bash
upload () {
  SIZE=$(stat -c %s "$1")
  RESPONSE=$(curl -s -X POST http://127.0.0.1:8000/api/v1/sources \
    -H "Authorization: Bearer $SLATE_TOKEN" -H 'Content-Type: application/json' \
    -d "{\"upload\":{\"content_type\":\"$2\",\"size\":$SIZE}}")
  PUT_URL=$(echo "$RESPONSE" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["put_url"];')
  curl -s -o /dev/null -X PUT "$PUT_URL" -H 'If-None-Match: *' -H "Content-Type: $2" --data-binary @"$1"
  echo "$RESPONSE" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["id"];'
}

IMAGE_ONE=$(upload /tmp/media/page-1.png image/png)
IMAGE_TWO=$(upload /tmp/media/page-2.png image/png)
NARRATION=$(upload /tmp/media/narration.mp3 audio/mpeg)
```

`If-None-Match: *` is part of the signature — drop it and object storage answers `400`. It is also
what makes the slot single-use, so an uploaded object can never be replaced.

Watch them become usable. The worker probes each upload and moves it from `ingesting` to `ready`:

```bash
for ID in $IMAGE_ONE $IMAGE_TWO $NARRATION; do
  curl -s -H "Authorization: Bearer $SLATE_TOKEN" http://127.0.0.1:8000/api/v1/sources/$ID; echo
done
```

Repeat until all three report `"status":"ready"` — it takes a couple of seconds. A source that says
`rejected` names the reason, such as `unsupported_container` or `resolution_exceeded`.

Now submit the recipe. Two pages of three seconds each, joined by a one-second cross-fade:

```bash
RUN_ID=$(php -r 'echo sprintf("%s-%s-4%s-8%s-%s", bin2hex(random_bytes(4)), bin2hex(random_bytes(2)),
  substr(bin2hex(random_bytes(2)),1), substr(bin2hex(random_bytes(2)),1), bin2hex(random_bytes(6)));')

curl -s -X POST http://127.0.0.1:8000/api/v1/runs \
  -H "Authorization: Bearer $SLATE_TOKEN" -H 'Content-Type: application/json' \
  -d "{\"id\":\"$RUN_ID\",\"recipe\":{\"delivery\":{
        \"pages\":[
          {\"image\":\"$IMAGE_ONE\",\"audio\":\"$NARRATION\",\"duration\":3.0},
          {\"image\":\"$IMAGE_TWO\",\"audio\":\"$NARRATION\",\"duration\":3.0}
        ],
        \"transitions\":{\"type\":\"fade\",\"duration\":1.0},
        \"quality_tier\":\"delivery\",
        \"width\":1536,\"height\":1024}}}"; echo
```

You choose the run `id` yourself — that is what makes a retry safe. Slate answers `202` with
`{"id":"…"}`. Poll until it succeeds:

```bash
curl -s -H "Authorization: Bearer $SLATE_TOKEN" http://127.0.0.1:8000/api/v1/runs/$RUN_ID; echo
```

`status` goes `queued` → `running` → `succeeded`. Then fetch the result and download it:

```bash
RESULT_URL=$(curl -s -H "Authorization: Bearer $SLATE_TOKEN" \
  http://127.0.0.1:8000/api/v1/runs/$RUN_ID/result \
  | php -r 'echo json_decode(stream_get_contents(STDIN), true)["url"];')

curl -s -o /tmp/delivery.mp4 "$RESULT_URL"
/app/runtime/ffmpeg/bin/ffprobe -v error -of json \
  -show_entries format=duration -show_entries stream=codec_name,width,height /tmp/delivery.mp4
```

```json
{
  "streams": [{"codec_name": "h264", "width": 1536, "height": 1024}, {"codec_name": "aac"}],
  "format": {"duration": "5.000000"}
}
```

Five seconds, because two three-second pages overlapped by a one-second fade give `3 + 3 - 1`. That
file is the thing Slate exists to produce.

The `width` and `height` must be `1536`×`1024` or `1024`×`1536` — those are the two audited canvases.
Your source images can be any size; Slate fits them to the canvas.

---

# Deploy to Laravel Cloud

**The one rule that breaks everything if you get it wrong: never set an environment variable for a
resource Laravel Cloud provisions for you.** When you attach a database, cache, queue or bucket,
Cloud writes all of its settings — the credentials *and* the selectors like `DB_CONNECTION`,
`QUEUE_CONNECTION`, `CACHE_STORE` and `FILESYSTEM_DISK` — into a managed environment file your app
reads. Any value you set yourself shadows Cloud's and breaks that resource.

This matters immediately: the local `.env` you just built sets `FILESYSTEM_DISK`, `QUEUE_CONNECTION`
and every `AWS_*` variable. **Those must never reach Cloud.**

## Before you start

Install and authenticate the CLI:

```bash
composer global require laravel/cloud-cli
cloud --version        # the commands below are written against v0.6.0
cloud auth             # browser-based OAuth
```

Then confirm which application you are about to change. `.cloud/config.json` is committed in this
repository and already names an organization and an application:

```bash
cat .cloud/config.json
cloud application:get --json
```

If those identifiers are not the application you mean, rebind before running anything that changes
state:

```bash
cloud repo:config <application> --organization=<organization>
```

The file holds only `organization_id` and `application_id`. Neither is a credential — the CLI
authenticates separately, per user — which is why the file is committed rather than ignored.

## 1. First deploy

`cloud ship` is the guided first run: it creates the application and environment, connects the
repository, and creates and attaches the database, which is the part the individual CLI flags do not
do reliably.

```bash
cloud ship
```

Walk the prompts: region → application name → repository → **decline the offer to sync your local
`.env`**. Accepting it uploads `FILESYSTEM_DISK`, `QUEUE_CONNECTION` and `AWS_*` from step 5 and
shadows the values Cloud is about to inject — the cardinal rule, broken on your first deploy. Accept
the database.

`ship` does not always write `.cloud/config.json`, so afterwards:

```bash
cloud repo:config
```

## 2. Create the bucket and attach it

```bash
cloud bucket:create -n --json \
    --name slate-production \
    --region <same region as the app> \
    --visibility private \
    --key-name slate-production \
    --key-permission read_write \
    --allowed-origins "https://<your app host>"
```

`bucket:create` takes every choice as a flag and validates them one at a time, so supply all of the
above rather than discovering them one error at a time. Keep `--visibility private`: Slate hands out
signed URLs and never needs public objects.

**Attaching the bucket is a dashboard step.** There is no CLI flag for it — `environment:update`
offers `--database-id`, `--cache-id` and `--websocket-application-id`, and nothing for buckets. In
the Cloud dashboard open your application → the environment → **Storage**, and attach the bucket you
just created. Cloud then injects the `AWS_*` settings and wires the default disk to it.

## 3. Create the managed queue

A managed queue scales to zero between jobs, which is the point of running Slate this way. Do not
provision a worker instance instead.

```bash
cloud instance:sizes --json                       # pick a size
cloud managed-queue:create <environment> -n --json --name slate --size <size>
cloud managed-queue:set-default <instance id> -n --json
```

`managed-queue:create` prints the new instance's `id`; that is the argument `set-default` wants.
Because the queue is Cloud-provisioned, Cloud injects `QUEUE_CONNECTION` — do not set it yourself.

## 4. Vendor `ffmpeg` into every build

The `runtime/` directory is gitignored, so the binary has to be installed at build time. Read the
environment's current build command first, then append to it rather than replacing it:

```bash
cloud environment:get <environment> --json --fields=buildCommand

cloud environment:update <environment> --force \
    --build-command="<the existing command> && php artisan slate:provision-ffmpeg"
```

You can set the same field in the dashboard under the environment's build settings. The build image
needs `xz` available, or provisioning fails with `Missing required 'xz' executable.`

## 5. Enable the scheduler

Slate runs `slate:prune-ingests` hourly to reclaim storage left by failed and expired uploads.
Without the scheduler that storage is never released and you pay for it forever.

There is no CLI command for this — enable the scheduler in the dashboard, in the environment's
settings. Confirm it afterwards:

```bash
cloud environment:get <environment> --json --fields=usesScheduler
```

## 6. Set the application variables

Only variables Cloud does **not** inject. Nothing here touches `DB_*`, `AWS_*`, `CACHE_STORE`,
`SESSION_DRIVER`, `QUEUE_CONNECTION` or `FILESYSTEM_DISK`.

```bash
cloud environment:variables <environment> -n --json --action=set --key=APP_ENV     --value=production
cloud environment:variables <environment> -n --json --action=set --key=APP_DEBUG   --value=false
cloud environment:variables <environment> -n --json --action=set --key=APP_URL     --value=https://<your app host>
cloud environment:variables <environment> -n --json --action=set --key=SLATE_INSTALLATION_SUBJECT_REF --value=<your installation reference>
```

Use a real installation reference here — `acme-slate-production`, not the `local-only-change-me`
placeholder.

## 7. Deploy and migrate

```bash
cloud deploy -n
cloud deploy:monitor -n
cloud command:run <environment> --cmd "php artisan migrate --force" -n --no-monitor
```

## 8. Bootstrap the deployed application

The local `--local` commands from step 7 of the walkthrough wrote to your throwaway SQLite file. The
deployed database has no Owner and no credential yet, and these are separate commands.

**The Owner.** Run `create-admin` from your machine but name the Cloud environment instead of passing
`--local`:

```bash
php artisan create-admin --email=you@example.com --name="Your Name" --environment=<environment>
```

Leave `--password` off again. The command prompts for the password locally, hashes it locally, and
sends only `--password-hash` to the environment — **the plaintext never leaves your machine**. You
can then sign in at `https://<your app host>/bfc/login`.

**The API credential.** `bfc:credential:mint` has no remote mode; it refuses to run without `--local`.
The supported way to mint in production is therefore to run it *inside* the environment, where
`--local` means that environment's own database:

```bash
cloud command:run <environment> -n --no-monitor \
    --cmd "php artisan bfc:credential:mint installation <your installation reference> \
           --kind=bearer --purpose=consumption --name='production client' --local"
```

The subject reference must match the `SLATE_INSTALLATION_SUBJECT_REF` you set in step 6 exactly, or
the credential will authenticate nothing.

> **The token is printed once, into that command's output**, which Cloud retains in its command
> history. If you would rather it never appeared there, mint it from the deployed console instead:
> sign in and use **Installation credentials** at
> `https://<your app host>/bfc/ui/credentials/installation`. Either way, if you think the value was
> seen by anyone else, rotate it with `bfc:credential:rotate`.

## 9. Check the deployment

Read the resolved configuration back out of the running application rather than assuming:

```bash
cloud tinker <environment> --code="echo config('database.default');"
cloud tinker <environment> --code="echo config('queue.default');"
cloud tinker <environment> --code="echo config('filesystems.default');"
cloud tinker <environment> --code="echo config('slate.api.installation_subject_ref');"
```

Then prove the pieces actually work:

```bash
# object storage round-trip on the default disk
cloud tinker <environment> --code="Storage::put('probe.txt','ok'); echo Storage::get('probe.txt'); Storage::delete('probe.txt');"

# the ffmpeg the build vendored
cloud command:run <environment> --cmd "php artisan tinker --execute='echo app(App\\Slate\\Driver\\Binary\\FfmpegLocator::fromSystem())->ffmpegPath();'" -n --no-monitor

# the app answers, and the API rejects an anonymous caller
curl -sS -o /dev/null -w '%{http_code}\n' https://<your app host>/
curl -sS -o /dev/null -w '%{http_code}\n' https://<your app host>/api/v1/runs/41414141-4141-4141-8141-414141414141
```

The last two should print `200` and `401`. If any configuration value looks wrong, check first
whether you set a variable that is shadowing one Cloud injects, and delete it.

Finally, run the walkthrough from step 8 against your deployed host with the production token. A
succeeded run and a downloadable MP4 is the only proof that the queue, the bucket and the vendored
`ffmpeg` are all working together.

---

# Configuration

Every value has a working default. Set only what you need to change.

| Variable | Default | What it controls |
| --- | --- | --- |
| `SLATE_INSTALLATION_SUBJECT_REF` | unset (`.env.example` ships `local-only-change-me`) | The installation reference an installation credential must match exactly. Leave it unset and only user-bound credentials work — there is no shared fallback. |
| `SLATE_FFMPEG_RELEASE_TAG` | `autobuild-2026-08-31-13-27` | Which BtbN release to download. |
| `SLATE_FFMPEG_ASSET` | `ffmpeg-n9.0.1-11-ge47273f4d9-linuxarm64-lgpl-9.0.tar.xz` | The archive filename inside that release. |
| `SLATE_FFMPEG_RUNTIME_PATH` | `runtime/` in the project root | Where `ffmpeg` is installed. Keep it inside the project — only the project directory survives a deploy. |
| `SLATE_FFMPEG_FONT_PATH` | `resources/fonts/NotoSans[wdth,wght].ttf` | The font captions are drawn with. |
| `SLATE_ALLOW_HOST_LOCAL_FANOUT` | `false` | Allows parallel rendering against a non-S3 disk. Single-host local development only; leave it off everywhere else. |

The rest of `config/slate.php` — input limits, queue timing, worker ceilings, measured render rates —
is tuned against Laravel Cloud's runtime and is deliberately not env-configurable. Read it before
changing it; the comments record what each number was measured against.

The two test-lane flags used above are `SLATE_REQUIRE_MINIO=1` and `SLATE_REQUIRE_FFMPEG=1`, which
turn a lane's "skip" into a failure so CI cannot silently pass without it. The test suite and
benchmark scripts read several other harness-only variables; grep `tests/` and `scripts/` if you need
the full set.

To run the object-storage test lane, start MinIO as in step 5 and:

```bash
SLATE_REQUIRE_MINIO=1 php artisan test --group=minio
```

Four tests run, and they create the bucket they need themselves.

---

# Using Slate

## The HTTP API

Five endpoints, all under `/api/v1`, all requiring a bearer credential:

| Method | Path | Purpose |
| --- | --- | --- |
| `POST` | `/api/v1/sources` | Register a source, by direct upload or by HTTPS URL. |
| `GET` | `/api/v1/sources/{source}` | Check whether a source is `ready`. |
| `POST` | `/api/v1/runs` | Submit a recipe. |
| `GET` | `/api/v1/runs/{run}` | Check run status. |
| `GET` | `/api/v1/runs/{run}/result` | Get the signed download URL. |

Source and run ids are UUIDs. The walkthrough in step 8 is a complete worked example of all five.

Registering a source returns `201` and a signed upload slot:

```json
{
  "id": "c12a53df-9ec6-48e9-8a4a-1f75a82cee80",
  "put_url": "https://object-storage.example/...?X-Amz-Signature=...",
  "headers": {"Host": ["object-storage.example"], "If-None-Match": ["*"]},
  "expires_at": "2026-09-22T22:46:09.061827Z",
  "object_key": "slate-sources/c12a53df-9ec6-48e9-8a4a-1f75a82cee80/source"
}
```

Send **every** header in `headers` with the `PUT`. `object_key` is informational — recipes reference
the source `id`, never a raw object key.

Instead of uploading you may hand Slate an HTTPS URL to fetch, with
`{"url": "https://media.example/video.mp4"}`. That returns `202` and the `ingest` queue pulls it.

Recipe formats, every error `reason`, the webhook signature scheme and the full list of rejection
reasons are in [`docs/API.md`](docs/API.md). Input limits and the reasoning behind them are in
[`docs/INPUT-RISKS.md`](docs/INPUT-RISKS.md).

## The web console

Slate's UI comes from the Built for Cloud package:

| Path | What it does |
| --- | --- |
| `/` | Landing page. |
| `/bfc/login` | Sign in. |
| `/bfc/members` | Invite and manage members. |
| `/bfc/me/sessions` | Your active sessions. |
| `/bfc/ui/credentials/installation` | Mint, rotate and revoke installation credentials. |

The same administration is available from the command line. Each takes the credential's row id, which
`bfc:credential:list` prints:

```bash
php artisan bfc:credential:list --local
php artisan bfc:credential:rotate <id> --local
php artisan bfc:credential:revoke <id> --local
```

All three refuse to run without `--local`. To administer a deployed environment, run them through
`cloud command:run <environment> --cmd "…"` as in step 8.

---

# Troubleshooting

**`Slate ingest requires an S3-backed default filesystem.`** The default disk is not S3. Locally,
that means step 5 was skipped or `.env` still says `FILESYSTEM_DISK=local`. On Laravel Cloud it means
the bucket is not attached to the environment — attach it in the dashboard, and do not set
`FILESYSTEM_DISK` or `AWS_*` yourself.

**Uploads sign fine but the `PUT` never connects.** The web app and the queue worker must both reach
the address in `AWS_ENDPOINT`. `127.0.0.1` means something different inside a container than on your
host; use the `slate-local` network name `minio` when anything runs in a container.

**``Missing required `xz` executable.``** `slate:provision-ffmpeg` cannot unpack the archive. Install
`xz-utils` in the container or build image.

**`exec: not found` when rendering, right after provisioning.** You are on macOS with a bind-mounted
container. PHP's file checks see stale results through the mount and the provisioner skips the
install. Re-run it with `--force`.

**`php artisan test` starts failing 13 tests on macOS after step 6.** The failures say `cannot execute
binary file`. Provisioning left a Linux arm64 `ffmpeg` in `runtime/`, so the render tests stop
skipping and try to run it on your Mac. Run the suite in the container, or `rm -rf runtime` to go
back to skipping that group on the host.

**Captions fail to render instead of using another font.** That is deliberate. Slate isolates
`fontconfig` so a missing or unloadable font file fails loudly rather than silently substituting
whatever font the host happens to have. Check `SLATE_FFMPEG_FONT_PATH` points at a real file.

**A source or run never leaves its first status.** Nothing is working the queues. Both are needed:
`php artisan queue:work --queue=default,ingest`.

**A `502` from the API.** Laravel Cloud returns one while the instance wakes from hibernation. Retry
with backoff. If you are retrying a run submission, reuse the same run `id` — Slate answers a
duplicate id with `409 run_id_conflict` and starts no second render.

---

# License

Slate is open-source software licensed under the [MIT license](https://opensource.org/licenses/MIT).
