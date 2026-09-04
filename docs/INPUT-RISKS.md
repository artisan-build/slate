# Input Risks

Slate treats every source as potentially hostile, regardless of whether its declared trust is
`trusted-origin` or `untrusted`. The declaration is recorded for future policy decisions; it does not
relax any check today.

## What Slate Checks

Before source ingest starts, Slate enforces the configured byte limit and checks the first 64 KiB for a
self-contained MP4/MOV/M4A, WebM/Matroska, MP3, WAV, or AAC signature. This leading-byte check rejects
recognized HLS and DASH manifests, concat scripts, and text inputs early. It cannot rule out a QuickTime
reference atom stored later in a MOV file.

After a source or direct bucket upload is staged into an isolated worker scratch directory, Slate scans
the complete ISO media box structure and rejects QuickTime reference movies before ffprobe or ffmpeg
runs. Direct uploads also receive the byte-limit and leading-container checks at this stage.

On the worker, ffprobe runs with the same process timeout and worker resource limits as rendering.
Slate rejects sources that exceed its duration, dimensions, stream-count, frame-rate, or decode-codec
allowlists, and records a typed rejection reason. Every local-file ffmpeg invocation disables standard
input, restricts nested protocols to `file`, strips source metadata, and applies duration and output-size
caps. No consumer deliverable contains stream-copied bytes that originated outside Slate.
Source-derived bytes are always transcoded; Slate's own encoded output may be stream-copied between
Slate's own stages, including into a final audio-muxed deliverable.

These checks reduce accidental resource exhaustion and block known playlist/reference mechanisms that
could make ffmpeg open another resource. They are not a proof that admitted bytes are safe.

## What Slate Cannot Check

Container and structural validation cannot rule out a memory-safety defect in an ffmpeg demuxer or
decoder. If crafted media exploits such a defect, the exploit runs on the queue worker. It can reach
that run's scratch directory and the bucket credential Laravel Cloud injects into the worker. The
relevant mitigation is worker blast-radius containment, short-lived isolated scratch, narrow bucket
permissions, resource limits, prompt ffmpeg security updates, and cleanup, not a claim that validation
makes untrusted media safe.

Magic-byte detection is intentionally conservative but cannot identify every malformed or novel
container. Structural limits also describe what ffprobe reports; a parser bug can occur before ffprobe
has enough information to report them.

## Integrator Responsibilities

Integrating applications should enforce their own upload and request-size limits before sending work to
Slate. Validate both declared MIME type and file magic, quarantine untrusted uploads, scan them with the
controls appropriate to the application's threat model, and retain enough provenance for incident
response. Do not proxy untrusted media bytes through the application's web tier; upload directly to
quarantine or object storage and submit only the resulting source reference to Slate.

Use `trusted-origin` only for bytes produced or controlled by a source whose security boundary you are
prepared to trust. It is an audit declaration, not a bypass or a safety guarantee.
