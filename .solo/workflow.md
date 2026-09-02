# Workflow — slate

Project profile for Solo-driven work in `artisan-build/slate` — **self-hosted, unmetered video
encoding on Laravel Cloud.** A static `ffmpeg` (built WITH `drawtext`) is vendored into the deploy
artifact by a build command, exactly the way `matte` vendors `bg-remover`. Laravel 13.30, plain app
skeleton for now.

## Phase & mode
- phase: **MVP assembler** (`~/Herd/brain/projects/slate/PRD-mvp.md`). Stage A is DONE and proven:
  ffmpeg + drawtext runs at 7.77× realtime on a real managed-queue worker, with a 180s visibility
  window and 8s cold provision.
- default mode: A-autonomous (set by Ed 2026-09-01)
- merge_policy: **merge when CI is green; no human PR code review**
- merge method: `gh pr merge --squash`
- repo shape: standalone app now; extract `packages/slate-server` only once the shape is proven
  (Ed, 2026-09-01). Do **not** pre-build the monorepo split.

## CI (the merge gate for Mode A)
- status: **established and green on PHP 8.5** via `.github/workflows/lint.yml` and `tests.yml`.
- **Mode A IS in force.** Merge when CI is green; no human PR code review is required.
- required minimum: pest (tests) + phpstan (static analysis). Pint for formatting.

## Hard gate (green before merge; verify on the committed SHA, clean tree)
- `composer stan` AND `composer lint:test` AND `composer test` — all three, at root.
- Establish these composer scripts in PR 1 if absent.

## Dependency install (fresh worktree/branch)
- `composer install --no-interaction`; then copy `.env.example` → `.env`, `php artisan key:generate`.
- Local PHP is Herd global 8.4. Do not raise or lower the project's PHP floor without Ed.

## Project-specific hard rules (learned experimentally 2026-09-01, arm64 Debian 12)

These are verified facts, not guesses. Re-deriving them costs a Cloud deploy each.

1. **NEVER use `drawtext`'s `font=` option. Always `fontfile=`.** A Cloud worker has no fonts and no
   `/etc/fonts/fonts.conf`; `font=Sans` fails the filter-graph init outright. Ship an OFL font in the
   repo and reference it by absolute path.
2. **NEVER hand ffmpeg a remote URL.** Its bundled HTTP stack truncates response bodies
   (`Stream ends prematurely at 48, should be 2848208`) even though DNS and TLS are fine. Stage inputs
   to local scratch with Laravel `Http`/`Storage`, then pass ffmpeg local paths only.
3. **The provision step MUST trim the extract.** The archive expands to 292MB; `ffplay`, `doc/`,
   `man/` and `presets/` are dead weight. ffmpeg alone is 90MB, ffmpeg+ffprobe 180MB.
4. **Install into `base_path(...)`, never outside the app root.** Only `/var/www/html` is the deploy
   artifact; a sibling-of-app-root install looks persistent on a live instance and is not.
5. **Use the LGPL build, not GPL.** It has no x264/x265, but it DOES have `libopenh264` (H.264),
   `libkvazaar` (H.265), `libsvtav1`, `libaom-av1`, `libvpx-vp9`, `prores_ks`. No GPL component needed.
6. **Ignore `h264_nvenc` / `h264_amf` / `h264_vulkan` / `*_v4l2m2m` in `-encoders`.** They are hardware
   encoders and are unusable on a Cloud worker despite being listed.
7. **`ffmpeg -h encoder=<name>` exits 0 for codecs that do not exist.** Never probe capability with it.
   Use `-encoders` and grep the table.
8. **Cardinal Cloud rule:** never set an env var for a Cloud-provisioned resource. Cloud injects all
   resource config (credentials AND selectors like `QUEUE_CONNECTION`/`DB_CONNECTION`) itself.
9. **`.cloud/config.json` is COMMITTED.** Never gitignore it.
10. **The LGPL build's H.264 encoder cannot reach x264 CRF 12 quality on flat-gradient content at any
    bitrate.** SSIM saturates at 0.996677 versus CRF 12's 0.998485, so quality tiers are operational
    choices, not parity targets.

## Toolchain conformance — the ride-along rule (STANDING, all projects)

Run the project's full conformance command (`composer ready`, or the stack equivalent) as part of
FINALIZING every PR, and **let whatever it changes ride along in that PR** as a single isolated commit
titled `composer ready`. Scope-discipline language in a brief never suspends this. If it is a genuine
no-op, say "run, no-op" — do not silently omit it.
