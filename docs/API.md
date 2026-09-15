# Slate API v1

Slate accepts authenticated assembly work and stores all source and result bytes in object storage. All
endpoints require a Built for Cloud Bearer credential issued for the fixed `slate.sources` application
purpose. Slate accepts Consumption credentials bound either to a package user account or to the exact
Installation subject configured by `SLATE_INSTALLATION_SUBJECT_REF`. Missing, malformed, unknown,
wrong-purpose, wrong-kind, wrong-account, and wrong-installation credentials all return `401`.
JSON request bodies use `Content-Type: application/json`.

After upgrading from an earlier Slate credential, mint a fresh replacement locally with the Built for
Cloud credential command and select `slate.sources`; all state-changing Built for Cloud commands must
include `--local`. Use a new disposable value first and confirm the target installation subject before
replacing any deployed credential. There is no shared production fallback when
`SLATE_INSTALLATION_SUBJECT_REF` is unset.

The declared `slate.delivery` Signing purpose names Slate's outbound webhook protocol but is not an
issuance choice in this release. Webhook signing remains the per-run secret protocol documented below
until Built for Cloud v0.13 provides cross-application HMAC installation/import. Slate configures Built
for Cloud v0.12.3 installation UI and JSON self-service issuance to offer only Bearer credentials.

Laravel Cloud may return a transient `502` while the API instance wakes from hibernation. Clients should
retry `502` responses with bounded exponential backoff. A retry of run submission must reuse the same run
`id`; the duplicate response described below makes this safe to reconcile.

## Sources

### POST /api/v1/sources

Submit exactly one of `upload` or `url`. Unsupported fields are rejected.

For a direct upload:

```json
{
  "upload": {
    "content_type": "video/mp4",
    "size": 123456
  }
}
```

`upload.content_type` is the declared MIME type and `upload.size` is the positive byte count. A valid
request returns `201`:

```json
{
  "id": "0199...",
  "put_url": "https://object-storage.example/...",
  "headers": {"If-None-Match": "*"},
  "expires_at": "2026-09-09T12:15:00.000000Z",
  "object_key": "slate-sources/0199.../source"
}
```

The upload PUT is single-use. Send every returned `headers` entry, including the signed
`If-None-Match: *`, with the bytes to `put_url` before `expires_at`. Omitting that condition invalidates
the signature and object storage returns `400 AccessDenied` with "headers present in the request which
were not signed". Replacing an already uploaded object is rejected. `object_key` is informational and
must never be submitted in a recipe; recipes use the source `id`.

Declared upload content types select type-specific admission. Video accepts `video/*`; narration accepts
`audio/*`; still images accept `image/png` and `image/jpeg`; and fonts accept `font/*`,
`application/font-sfnt`, `application/vnd.ms-opentype`, or `application/x-font-ttf`. Slate verifies the
corresponding container, image, or sfnt magic bytes rather than trusting the declaration.

For a remote pull:

```json
{"url": "https://media.example/video.mp4"}
```

`url` must be HTTPS and resolve to public addresses. Redirects are not followed. A valid request returns
`202` with `{"id":"0199..."}` and Slate transfers it asynchronously on the ingest queue.

Invalid source submissions return `422` with reason `invalid_source`.

### GET /api/v1/sources/{source}

Returns `200` with `id`, `status`, nullable `rejection_reason`, `bytes`, and nullable `content_type`:

```json
{
  "id": "0199...",
  "status": "ready",
  "rejection_reason": null,
  "bytes": 123456,
  "content_type": "video/mp4"
}
```

Source `status` is one of `pending`, `ingesting`, `ready`, `failed`, or `rejected`. A foreign or unknown
source returns identical `404` behavior with reason `source_not_found`.

## Runs

### POST /api/v1/runs

Fields are `id`, `recipe`, optional `trust`, and optional nullable `webhook_url`. `id` is a caller-chosen
UUID used for idempotency. `trust` is `trusted-origin` or `untrusted` and defaults to `untrusted`;
hardening is identical for both. `webhook_url` must be an HTTPS public destination.

`recipe` contains exactly one `delivery` or `highlights` object. Every media reference is a source UUID
owned by the authenticated subject and already in `ready` state. Raw object keys and unsupported fields
are rejected.

Book delivery:

```json
{
  "id": "41414141-4141-4141-8141-414141414141",
  "recipe": {
    "delivery": {
      "pages": [
        {
          "image": "11111111-1111-4111-8111-111111111111",
          "audio": "22222222-2222-4222-8222-222222222222",
          "duration": 3.0,
          "captions": {
            "font": "33333333-3333-4333-8333-333333333333",
            "words": [
              {"text": "Hello", "x": 120, "y": 760, "start": 0.2, "end": 1.2}
            ]
          }
        },
        {"image": "44444444-4444-4444-8444-444444444444", "audio": "55555555-5555-4555-8555-555555555555", "duration": 3.0}
      ],
      "transitions": {"type": "fade", "duration": 1.0},
      "quality_tier": "delivery",
      "width": 1536,
      "height": 1024
    }
  }
}
```

`pages` is an ordered list of at least two pages. Each page has source IDs `image` and `audio`, a
positive `duration` in seconds, and optional `captions`. Captions contain a font source ID and 1-64
ordered `words`; each word has `text`, pixel coordinates `x` and `y`, and increasing second offsets
`start` and `end` within the page. Dimensions must be the audited `1536` by `1024` or `1024` by `1536`
canvas.

Highlight delivery:

```json
{
  "id": "61616161-6161-4161-8161-616161616161",
  "recipe": {
    "highlights": {
      "source": "77777777-7777-4777-8777-777777777777",
      "clips": [
        {"start": 1.0, "end": 3.0},
        {"start": 5.0, "end": 7.0}
      ],
      "transitions": {"type": "fade", "duration": 1.0},
      "quality_tier": "delivery"
    }
  }
}
```

`source` is one ready video source ID. `clips` is an ordered list of at least two increasing,
non-negative `start`/`end` ranges in seconds. Slate coarse-cuts the source into bucket intermediates,
precisely transcodes every clip, and assembles the output. Both recipes currently require transition
type `fade` with duration `1.0`. `quality_tier` is `intermediate` or `delivery`.

A valid submission returns `202` with `id`. When `webhook_url` is present it also returns the
single-reveal `webhook_secret`; Slate stores that secret encrypted and never returns it again.

Malformed recipes return `422` reason `malformed_recipe`. A foreign or unknown source ID returns the
same `422` body with reason `source_not_found`, and no run or job is created. An owned source not yet
ready returns `422` reason `source_not_ready`. An unsafe webhook returns `422` reason
`invalid_webhook_destination`.

Submitting an existing run `id` returns `409` with reason `run_id_conflict` and does not dispatch work,
regardless of whether the original run is queued, running, or complete.

### GET /api/v1/runs/{run}

Returns `200` with `id`, `status`, `trust`, and nullable `rejection_reason`. Run `status` is `queued`,
`running`, `succeeded`, or `failed`. A foreign or unknown run returns `404` reason `run_not_found`.

### GET /api/v1/runs/{run}/result

This endpoint intentionally changed from the former per-operation `results` array. A succeeded run now
returns the one delivery output as a signed URL:

```json
{
  "id": "41414141-4141-4141-8141-414141414141",
  "expires_at": "2026-09-09T12:15:00.000000Z",
  "url": "https://object-storage.example/slate-runs/.../output.mp4?signature=..."
}
```

Pass `include_intermediates=1` to include an `intermediates` array. Each entry has `operation_index` and
its signed `url`. Intermediates are diagnostic page or precise-clip outputs, not the consumer
deliverable. Signed URLs expire at `expires_at`.

A queued or running result returns `409` reason `result_not_ready`. A failed result returns `409` reason
`run_failed`, plus `status` and nullable `rejection_reason`. A foreign or unknown run returns `404`
reason `run_not_found`.

## Webhooks

Terminal runs post one JSON payload to `webhook_url` without result bytes or secrets:

```json
{
  "event": "run.succeeded",
  "run": {
    "id": "41414141-4141-4141-8141-414141414141",
    "status": "succeeded",
    "trust": "untrusted",
    "rejection_reason": null
  },
  "timestamp": 1788955200
}
```

`event` is `run.succeeded` or `run.failed`. Slate sends decimal Unix seconds in `X-Slate-Timestamp` and
sets `X-Slate-Signature` to lowercase hex HMAC-SHA256 of
`X-Slate-Timestamp + "." + exact_raw_request_body`, keyed by `webhook_secret`. Verify against the raw
body, use a constant-time comparison, reject stale timestamps, and deduplicate by run ID and terminal
status. Delivery uses three attempts with backoff and no redirects.

## Rejection Reasons

Input rejection reasons returned as `rejection_reason` are `source_too_large`, `unsupported_container`,
`referenced_media`, `probe_failed`, `duration_exceeded`, `resolution_exceeded`, `stream_count_exceeded`,
`decode_codec_not_allowed`, `frame_rate_exceeded`, `video_stream_missing`, `audio_stream_missing`, `clip_range_invalid`,
`operation_count_exceeded`, `total_output_duration_exceeded`, and `render_job_count_exceeded`.
