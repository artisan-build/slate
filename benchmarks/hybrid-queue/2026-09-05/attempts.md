# Queued Hybrid Delivery Benchmark Attempt Ledger - 2026-09-05

| Order | Outcome | Retained | Reason |
|---:|---|---|---|
| 1 | infrastructure setup stopped | no | Generated `.env` supplied a conflicting database URL; no benchmark cell was emitted. |
| 2 | diagnostic repetition stopped | no | Trace confirmed the same database URL conflict; no benchmark cell was emitted. |
| 3 | infrastructure migration stopped | no | MySQL rejected a BfC trigger because binary logging required elevated function-creator privileges; no benchmark cell was emitted. |
| 4 | infrastructure migration stopped | no | MySQL rejected a BfC-generated index name over its 64-character limit; no benchmark cell was emitted. The final harness moved to PostgreSQL. |
| 5 | post-submission invalid/censored pilot | no | The database queue's default 90-second retry window derived a 70-second driver timeout. The run timed out at 71.84 seconds and emitted no complete comparison cell, so it was excluded rather than treated as a measured arm. |
| 6 | complete measurement run 1 | yes | Six cells retained; arm order `single,fanout`; every worker-direction assertion passed. |
| 7 | complete measurement run 2 | yes | Six cells retained; arm order `single,fanout`; every worker-direction assertion passed. |
| 8 | complete measurement run 3 | yes | Six cells retained; arm order `fanout,single`; every worker-direction assertion passed. |

No complete post-submission cell was discarded. No fan-out result that failed to prove multiple workers
was reported: every retained fan-out video recorded all four worker hostnames. Every retained queued
single video recorded one hostname in execution evidence.
