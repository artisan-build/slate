# Queued Hybrid Delivery Benchmark - 2026-09-05

## Scope

This benchmark compares queued single-job-per-video execution with queued per-operation fan-out. Both
arms use the same published 14-by-40-second, 1536x1024, 24 fps Intermediate input fixture, producing
13,128 output frames through 27 hybrid segment operations and one final copy concat. Four arm64 workers
were each constrained to 1 vCPU and 2 GiB. PostgreSQL provided the shared database queue and MinIO the
shared object store.

Each clock starts immediately before the first source is staged and submitted. Latency is measured from
that clock to each visible result; pool drain is measured from the same clock until the queue, including
reserved jobs, returns to zero. `measurements.jsonl` contains all 18 retained cells, their arm order,
per-video latencies, worker evidence, operation headroom and drain time.

## Four-Worker Measurements

Each value is the median of three runs followed by the observed minimum-maximum range. A positive
fan-out delta is slower; a negative delta is faster.

| Concurrent videos | Arm | Mean video latency | Pool drain | Fan-out point estimate |
|---:|---|---:|---:|---|
| 1 | queued single | 13.602s (10.362-18.346) | 13.921s (10.578-19.093) | baseline |
| 1 | fan-out | 8.548s (7.682-20.940) | 8.872s (7.909-22.005) | latency -37.2%; drain -36.3% |
| 3 | queued single | 18.717s (16.411-32.018) | 20.553s (17.378-34.772) | baseline |
| 3 | fan-out | 19.433s (15.822-20.336) | 20.264s (16.249-21.092) | latency +3.8%; drain -1.4% |
| 5 | queued single | 24.307s (16.238-24.526) | 32.706s (22.912-35.233) | baseline |
| 5 | fan-out | 26.468s (22.781-40.580) | 28.555s (24.187-45.640) | latency +8.9%; drain -12.7% |

All three latency ranges overlap their comparison arm. Three observations support descriptive medians,
not confidence in an effect; even three paired wins have a two-sided sign-test p-value of 0.25. The
one-video three-run median was lower under fan-out. The 3- and 5-video latency and drain differences are
within observed noise and inconclusive. This is mixed evidence, not a throughput win.

## Headroom

These are median headrooms against the derived 160-second driver timeout and 180-second queue visibility
window. Driver headroom uses the peak operation; visibility headroom uses the peak queued job.

| Concurrent videos | Queued single driver / visibility | Fan-out driver / visibility |
|---:|---:|---:|
| 1 | +159.137s / +167.718s | +157.942s / +177.942s |
| 3 | +157.719s / +162.854s | +158.581s / +177.442s |
| 5 | +158.310s / +160.470s | +158.392s / +176.844s |

## Ten-Worker Extrapolation

**Every value in this section is EXTRAPOLATED, not measured. Confidence is low.**

The work-conserving model separates the measured median submission time from post-submission work:

```text
T10 = S4 + (T4 - S4) * (U4 / U10)
```

`T4` is the four-worker median latency or drain time, `S4` is median submission time, `U4` is workers
used in the measured pool, and `U10` is workers available in the modelled pool. For queued single,
`U4 = min(video_count, 4)` and `U10 = min(video_count, 10)` because one video cannot use two workers.
For fan-out, `U4 = 4` and `U10 = 10`. Median submission times in milliseconds were single
769/2493/6429 and fan-out 779/4199/6893 for 1/3/5 videos respectively.

| Concurrent videos | Queued single latency / drain | Fan-out latency / drain |
|---:|---:|---:|
| 1 | 13.602s / 13.921s | 3.887s / 4.016s |
| 3 | 18.717s / 20.553s | 10.293s / 10.625s |
| 5 | 20.731s / 27.451s | 14.723s / 15.558s |

The model assumes perfectly work-conserving scheduling and linear scaling of all post-submission work.
It does not model nonlinear object-store contention, queue coordination, worker startup, or the wide
variance in the three measured runs. It is a capacity sketch, not evidence of a 10-worker effect.

## Default

Fan-out and the 10-worker ceiling remain the defaults. That choice is based on the suggestive lower
one-video median and drain observations, plus separately understood failure-granularity and
visibility-timeout benefits. Every measured comparison needs more samples before claiming an effect.

## Corrected Premise

The exact published 28-operation hybrid assembly did not take approximately 117 seconds. Queued-single
job processing in the retained records was 9.588-28.209 seconds. The historical 116.571-second figure
is the sum of upstream source-building operations; those operations are not distributed by either
hybrid arm and cannot honestly be included in this pair's submission clock.
