<div align="center">

# log-anomaly-detector

**Unsupervised anomaly detection for HTTP traffic logs — classic Machine Learning, in PHP.**

[![PHP](https://img.shields.io/badge/PHP-8.4-777BB4?logo=php&logoColor=white)](https://www.php.net)
[![Flight PHP](https://img.shields.io/badge/Flight%20PHP-v3-2EA043)](https://flightphp.com)
[![PHP-ML](https://img.shields.io/badge/PHP--ML-0.10-D9534F)](https://php-ai.com)
[![SQLite](https://img.shields.io/badge/SQLite-3-003B57?logo=sqlite&logoColor=white)](https://sqlite.org)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%208-8B5CF6)](phpstan.neon.dist)
[![License](https://img.shields.io/badge/license-MIT-lightgrey)](LICENSE)

</div>

---

## 1. Overview

This project detects unusual records in HTTP traffic logs using **unsupervised Machine
Learning**. There are no labels such as `normal` / `anomaly` anywhere in the input — no
one tells the algorithm what an attack looks like. It receives only the structure of the
data: method, endpoint, status, timing, size. From that alone it discovers dense
patterns and flags records that fit none of them.

The pipeline, end to end:

```text
HTTP Logs
    ↓
Feature Extraction      (strings → numeric vectors)
    ↓
Normalization           (all features on a comparable scale)
    ↓
DBSCAN                  (density-based clustering)
    ↓
Clusters + Noise
    ↓
noise → anomaly candidate   (domain interpretation)
    ↓
SQLite                  (atomic persistence)
    ↓
Flight API + dashboard
```

> **Core rule:** DBSCAN produces *cluster membership*. A point that belongs to no
> cluster is a **noise point**. This project reports noise as an anomaly — an
> interpretation of the domain, not a property of the algorithm (see [§12](#12-why-noise-becomes-anomaly)).

## 2. Why this project exists

The goal is to study, using PHP:

- classic Machine Learning and **unsupervised learning**;
- **feature engineering** — turning raw records into numeric vectors;
- **categorical encoding** — one-hot, feature hashing;
- **normalization** — why scale matters for distance;
- **distance metrics** — Euclidean geometry over feature space;
- **clustering** and **DBSCAN** in particular;
- **anomaly detection** as an interpretation layer over clustering.

[PHP-ML](https://php-ai.com) is used **deliberately**: it makes every concept visible
and hackable in PHP itself, without a Python toolchain hiding the mechanics behind a
scikit-learn one-liner. The library is confined to one infrastructure namespace —
see [§16](#16-php-ml-isolation).

## 3. The problem

Consider a small slice of HTTP logs:

```text
GET  /users       200  120ms
GET  /users       200  118ms
GET  /users       200  125ms

POST /payments    201  320ms
POST /payments    201  340ms

GET  /.env        404    8ms
```

The first records form two **dense** groups: same method, same endpoint, same status
class, similar timing. `GET /.env` is different on *every* axis at once — an endpoint no
one else requests, a different method, an error status, a suspiciously fast response.

A human sees it instantly. The question this project answers: **how do we make that
judgment computable?** The answer is: encode each record as a point in numeric space,
so "behaves like the majority" becomes "close to other points", and "unusual" becomes
"far from everything".

## 4. Supervised vs unsupervised learning

| | Supervised | Unsupervised |
|---|---|---|
| Input | `X → known label Y` | `X` only |
| Example | `transaction → fraud / not fraud` | raw logs, no labels |
| Learns | mapping from X to Y | structure of X |
| This project | — | `HTTP logs → DBSCAN → clusters + noise` |

In supervised fraud detection, someone had to label thousands of transactions first.
Here **no label exists during analysis**. The algorithm observes only how records
distribute. That is exactly what makes it useful for logs: you rarely have labeled
attack data, but you always have traffic.

## 5. What is clustering

Clustering groups records by **similarity** — points close together (under a distance
measure) end up in the same group. Applied to the logs above:

```text
Cluster A              Cluster B              Noise
GET  /users            POST /payments         GET /.env
GET  /users            POST /payments
GET  /users
```

Two honest caveats:

1. A cluster is a **statistical** statement ("these records resemble each other"), not
   automatically "normal". A dense cluster of identical 500-error bursts is dense but
   hardly healthy.
2. This project adopts a **domain heuristic**:

   ```text
   dense pattern → expected behavior
   noise         → anomaly candidate
   ```

   The heuristic is the application's choice; the clustering math is neutral.

## 6. Feature engineering

Algorithms do not receive `GET`, `/users`, `200` as semantic concepts. Every input must
become a **numeric vector**. In this project:

```text
HttpLogEntry
     ↓  FeatureExtractor
FeatureVector   (33 floats)
```

### HTTP method — one-hot encoding

The method is categorical, so it becomes a one-hot block:

```text
GET  → [1,0,0]
POST → [0,1,0]
PUT  → [0,0,1]
```

Why not `GET = 1, POST = 2, PUT = 3`? Numeric coding implies **ordinal relations that
do not exist**: it would make `PUT` "greater than" `GET`, and `GET` vs `PUT` (distance
2) twice as different as `GET` vs `POST` (distance 1). One-hot gives every category the
same pairwise distance, expressing only "same or different".

### Endpoint — normalize, then feature hashing

Raw endpoints are too diverse (`/users/123`, `/users/456`, `/products?page=1`…). Two
normalization steps first:

```text
/users/123        → /users/{n}       (fully-numeric segments collapse)
/products?page=1  → /products        (query string dropped)
```

`/v2/users` and `/oauth2/callback` stay intact — only *entirely* numeric segments
collapse. Then **feature hashing** maps the normalized path into a fixed vector:

```text
normalized endpoint
     ↓
crc32
     ↓
mod 16 (bucket)
     ↓
one-hot bucket vector
```

This handles **unbounded endpoint cardinality with a fixed 16-dimension budget**, and
unseen endpoints still encode consistently. The trade-off: two distinct endpoints can
land in the same bucket (**collision**). With low cardinality after normalization the
practical impact is small — see [§23](#23-limitations).

### HTTP status — one-hot class, not a quantity

Status codes look numeric but are **categories**. Treating `200` and `500` as
continuum values would imply `200` is "closer to `404`" than `500` is — a fake
relationship (is 404 really "halfway healthy"?). Instead each code maps to its HTTP
class:

```text
1xx  2xx  3xx  4xx  5xx     (5 dims, one-hot)
```

Consequence: `200` and `201` become the **same** class; `404` and `500` become
**different** classes. The exact code is intentionally lost after encoding — see
[§23](#23-limitations).

### Numeric features

Three features stay numeric (scaling happens later, [§8](#8-normalization)):

| Feature | Meaning |
|---|---|
| `response_time` | milliseconds |
| `request_size` | bytes |
| `hour` | 0–23, linear |

## 7. Feature vector

One log entry → one vector with a fixed layout of **33 dimensions**:

| Block | Dims |
|---|---|
| method (one-hot, 9 HTTP verbs) | 9 |
| endpoint (hash buckets) | 16 |
| status class (one-hot) | 5 |
| numeric (response_time, request_size, hour) | 3 |
| **total** | **33** |

Conceptually:

```text
[
  method...,        // 9 values, one "1"
  endpoint...,      // 16 values, one "1"
  status...,        // 5 values, one "1"
  response_time,
  request_size,
  hour
]
```

The layout order is part of the contract between extractor, normalizer, and detector.

## 8. Normalization

Numeric features live in wildly different ranges:

```text
response_time = 120
request_size  = 250000
hour          = 10
```

Euclidean distance sums squared differences. Without scaling, `request_size` would
contribute `250000²` while `hour` contributes at most `23²` — **request_size would
dominate every distance**, and method/endpoint/status would be invisible noise by
comparison.

The project uses **Min-Max normalization**, fitted per batch:

```text
x' = (x - min) / (max - min)        → x' ∈ [0, 1]
```

Flow:

```text
fit   → learn min/max of each feature over the whole batch
transform → apply the learned min/max to every vector
```

Parameters are learned **once per batch** and reused for every record — never
recomputed per record. A constant feature (`min == max`, e.g. every request has the
same size) would divide by zero, so it maps to `0`.

## 9. Distance

Vectors are compared with **Euclidean distance**:

```text
d(a, b) = √( Σ (aᵢ - bᵢ)² )
```

Intuition: each feature is a coordinate; each log is a point; distance is the straight
line between two points.

```text
small distance → similar records
large distance → different records
```

That single number is all DBSCAN gets — which is why [§6](#6-feature-engineering) and
[§8](#8-normalization) decisions matter so much: they *define* what "similar" means.

## 10. Feature geometry

This is the part most worth internalizing: **one-hot categorical features produce
large, discrete distances.** Take two records differing only in method:

```text
GET  → [1, 0]
POST → [0, 1]

d = √((1-0)² + (0-1)²) = √2 ≈ 1.414
```

Compare that with the default `epsilon = 0.35`:

```text
1.414  >  0.35   →  different method → generally not neighbors
```

The same logic holds for endpoint buckets and status classes: any single categorical
mismatch alone already contributes √2, well beyond epsilon. Two records can only be
neighbors if they agree on method, endpoint bucket, **and** status class — numeric
features only fine-tune distance *within* an agreeing group.

Important framing: **this is a decision about the feature space, not a universal
property of DBSCAN.** With different encoding, scaling, or epsilon, geometry would
behave differently. `tests/Unit/FeatureGeometryTest.php` locks these distances so the
behavior cannot drift silently.

## 11. DBSCAN

**DBSCAN** — *Density-Based Spatial Clustering of Applications with Noise*. It finds
clusters as **dense regions** separated by sparse space, and explicitly has an output
category for points that fit nowhere.

Two parameters:

| Parameter | Default | Meaning |
|---|---|---|
| `epsilon` | 0.35 | neighborhood radius: `distance < epsilon` → neighbor |
| `minimum_samples` | 5 | how many neighbors make a region "dense" |

Three kinds of points:

- **core point** — has at least `minimum_samples` neighbors within `epsilon` (itself
  included). Sits inside a dense region.
- **border point** — within epsilon of a core point but not core itself. Joins the
  cluster from its edge.
- **noise point** — neither core nor border. Belongs to no cluster.

```text
● ● ● ●

  ● ● ●

                      ×
```

```text
● = dense region (cluster)
× = noise
```

Implementation is `Phpml\Clustering\DBSCAN` (PHP-ML), Euclidean distance, strict
`<` epsilon. Cluster ids are assigned in discovery order — deterministic for a fixed
input order.

> **Implementation note:** PHP-ML's `DBSCAN::cluster()` renumbers member keys
> internally, destroying original sample indices. The wrapper reconstructs
> assignments via multiset matching — identical vectors always receive identical
> labels, so consumption in dataset order is unambiguous.

## 12. Why noise becomes anomaly

Be precise about what DBSCAN does and does not know:

- DBSCAN has **no concept** of `security anomaly`, `attack`, or `failure`.
- Its entire output vocabulary is: `cluster membership` and `noise`.

The project applies one domain rule on top:

```text
noise → anomaly candidate
```

Rationale: in this feature space, "dense" means "behaves like the predominant traffic
patterns"; a noise point matches no predominant pattern. That makes it interesting —
a candidate for a human to look at.

This is an **interpretation, not a proof**. Not every noise point is an attack: it may
be a rare-but-legitimate request, a new endpoint nobody has hit yet, or one more
5xx in an outage. The API says `{"anomaly": true}` — meaning *this record fits no
dense pattern*, nothing stronger.

## 13. No confidence score

The API never returns:

```json
{"anomaly": true, "confidence": 0.97}
```

DBSCAN produces no probability. A point is a neighbor, a cluster member, or noise —
there is no mathematical definition behind a "confidence" number for this output.
Inventing one (or laundering distance-to-nearest-cluster into a fake probability)
would be **misleading**: consumers would rank alerts by a number that measures
nothing. So the project reports membership only, and says so plainly.

## 14. Why `/detect` returns 501

`POST /api/v1/detect` is intentionally `501 Not Implemented`.

The current implementation runs DBSCAN **in batch**: every analysis clusters the full
input at once. There is no trained model object with a `predict($newLog)` method, as
supervised classifiers have.

A real single-record detector would need to persist:

```text
training/reference vectors
normalization parameters (min/max per feature)
epsilon
minimum_samples
```

…and define a **formal strategy** for classifying new points — e.g. "anomaly when the
epsilon-neighborhood in the reference set has fewer than `minimum_samples` members".
That is designed, deliberately not built. See [Limitations](#23-limitations).

## 15. Architecture

Ports and Adapters, kept small:

```text
        Flight (HTTP)
            ↓
       Controller          thin: validation → DTO → response, no ML/SQL
            ↓
       Application         AnalyzeLogs — pipeline orchestration
            ↓
         Domain            entities, value objects, PORTS
            ↑
      Infrastructure       adapters: PHP-ML wrapper, CSV loader,
                           SQLite repositories
```

The **Domain** layer knows none of:

```text
Flight   ·   Phpml\   ·   SQLite
```

It declares **ports** (interfaces): `AnomalyDetector`, `CategoricalEncoder`,
`Normalizer`, repositories, loader. Infrastructure implements them. Controllers call
use cases; use cases call ports. Swapping PHP-ML for another library, or SQLite for
Postgres, touches only Infrastructure.

## 16. PHP-ML isolation

The one hard boundary of the codebase:

```text
App\Domain\Anomaly\AnomalyDetector          (port — interface)
              ↑ implements
App\Infrastructure\MachineLearning\PhpMlDbscanDetector
```

The domain knows:

```text
detect(vectors)
```

It does **not** know:

```text
Phpml\Clustering\DBSCAN
```

`use Phpml\` appears **only** inside `app/Infrastructure/MachineLearning/` — enforced
by convention, verified by review, and the reason `composer analyse` exists at level 8.

## 17. Persistence

Two tables in SQLite:

```text
analysis_runs    one row per analysis: algorithm, epsilon, minimum_samples,
                 sample/cluster/anomaly counts, timestamps
log_entries      one row per analyzed log, FK → analysis_runs
```

Run + entries are written **atomically in a single transaction**:

```text
BEGIN
  ↓
insert analysis_run
  ↓
insert log_entries (batch)
  ↓
COMMIT
```

On any failure mid-write:

```text
ROLLBACK   →  no orphan analysis_runs row
```

`log_entries.analysis_run_id` carries `FOREIGN KEY … ON DELETE CASCADE` — deleting a
run removes its entries. `PRAGMA foreign_keys = ON` is set per connection. Locked by
integration test.

## 18. Dataset

`datasets/development.csv` — 1,400 rows, generated by
`php scripts/generate_dataset.php` with **seed 42** (always byte-identical).

| Normal profiles | Injected anomaly kinds |
|---|---|
| `GET /users` · `GET /users/{id}` | 5xx error bursts on normal endpoints |
| `POST /payments` · `GET /health` | large-payload floods (60k–340k bytes) |
| `POST /api/search` · `GET /products/{id}` | vulnerability scanners (`/.env`, `/wp-admin.php`, …) at odd hours |
| | slowloris-style slow requests |

~2.5% of rows are anomalies, spread across sparse sub-kinds so none becomes dense
enough to form its own cluster.

**This is a synthetic, educational dataset.** It exists to validate behavior and let
you experiment with known ground truth:

```text
synthetic dataset ≠ production benchmark
```

## 19. Tests

| Suite | Location | Contents |
|---|---|---|
| **Unit** | `tests/Unit/` | `FeatureExtractor`, encoder, `Normalizer`, DBSCAN wrapper, value objects, geometry, CSV + nginx access.log parsers — isolated, no DB |
| **Integration** | `tests/Integration/` | real SQLite + real migrations: repositories, atomic persistence, FK/cascade, full pipeline, HTTP controllers |

Highlight: **`FeatureGeometryTest`** documents — and locks — the mathematical
decisions of [§10](#10-feature-geometry): same-profile records land inside epsilon;
any single categorical mismatch (√2) lands outside. Change the geometry and this test
forces the conversation.

Deterministic everywhere: fixed datasets, seeded generator.

```bash
composer test      # PHPUnit
composer analyse   # PHPStan level 8 — never lowered
composer check     # both
```

There is no CI workflow and no deploy pipeline — quality gates run locally
via `composer check`.

## 20. Running locally

Requires PHP 8.4+ (`pdo`, `pdo_sqlite`, `mbstring`, `json`) and Composer 2.

```bash
git clone https://github.com/yanpenalva/log-anomaly-detector.git
cd log-anomaly-detector

composer install
cp app/config/config_sample.php app/config/config.php
cp .env.example .env

php runway migrate
composer start
```

Open `http://localhost:8000` — the dashboard. Paste logs, tune epsilon /
minimum samples, run the analysis, inspect anomalies, browse persisted runs.

Fresh database at any time: delete `database.sqlite`, run `php runway migrate`.

### Batch analysis CLI

Analyze a file from the command line — same pipeline, same persistence, no HTTP:

```bash
php runway analyze datasets/development.csv
php runway analyze /var/log/nginx/access.log -m 3
php runway analyze access.log --epsilon=0.35 --minimum-samples=5 --limit=20
```

Supported formats (chosen by extension):

| Extension | Format |
|---|---|
| `.csv` | development dataset header (`method,endpoint,status_code,response_time,request_size,hour`) |
| `.log` | nginx **combined** format, optionally followed by `$request_time` (seconds → ms); malformed lines are skipped |

Options override `config.php`/`.env` defaults for this run only. The summary
prints run id, samples, clusters, anomaly count and the first `--limit`
anomaly rows. Results are persisted like any HTTP analysis.

```text
$ php runway analyze access.log -m 3
Loaded 7 entries from access.log
run 4 · dbscan · samples 7 · clusters 2 · anomalies 1
anomalies (first 1 of 1):
  GET     /.env                                    404 9ms 9B @ 03h
```

## 21. Docker

Docker is an **optional, reproducible local environment** — not a deployment target.

```bash
docker compose up --build
```

Same app on `http://localhost:8000`. No host PHP needed.

The image is deliberately simple: PHP 8.4 CLI + Composer + `pdo_sqlite` + `mbstring`
+ the app. During build it copies `config_sample.php` → `config.php` (fresh clones
have none), and migrations run on container start. `docker-compose.yml` provides the
environment variables, which override config defaults at runtime via
`Config::mergeEnv` — no `.env` file inside the container. SQLite data persists in a
named volume.

## 22. API

All endpoints under `/api/v1`:

| Method | Route | Description |
|---|---|---|
| `GET` | `/api/v1/health` | liveness |
| `POST` | `/api/v1/analyze` | batch analysis + atomic persistence |
| `GET` | `/api/v1/analysis` | recent runs (`?limit=1..200`) |
| `GET` | `/api/v1/analysis/{id}` | run detail + anomalies (capped, `anomalies_truncated`) |
| `POST` | `/api/v1/detect` | **501** — see [§14](#14-why-detect-returns-501) |

`POST /api/v1/analyze`:

```json
{
  "logs": [
    {"method": "GET", "endpoint": "/users", "status_code": 200,
     "response_time": 118, "request_size": 1024, "hour": 10}
  ],
  "epsilon": 0.35,
  "minimum_samples": 5
}
```

Response:

```json
{"data": {"run_id": 5, "algorithm": "dbscan", "epsilon": 0.4,
          "minimum_samples": 5, "samples": 133, "clusters": 3, "anomalies": 3}}
```

Errors: invalid JSON → `400`; non-object body → `400`; invalid log field → `422`
(with the offending index); too many logs → `422`; oversized payload → `413`.
Limits: `anomaly.max_payload_bytes` (2 MiB), `anomaly.max_logs_per_request` (10k).
The API **never** accepts filesystem paths — inline logs only.

## 23. Limitations

1. **Hash collisions** — distinct endpoints can share a bucket (hashing trick). With
   16 buckets over a low post-normalization cardinality, practical impact is small;
   it grows with endpoint diversity.
2. **Linear `hour`** — 23 and 0 are maximally far apart though they are adjacent.
   Cyclical sin/cos encoding was tested and rejected: with min-max + Euclidean it
   spreads same-profile hours beyond epsilon and fragments clusters.
3. **Min-max is outlier-sensitive** — one huge `request_size` stretches the scale and
   compresses everything else toward 0. Z-score or robust scaling are alternatives.
4. **DBSCAN is batch-only** — every analysis re-clusters the full input; no
   incremental inference (hence the 501, [§14](#14-why-detect-returns-501)).
5. **Exact HTTP status is lost** after class encoding — within one class, `404` vs
   `400` are indistinguishable to the model. Deliberate, to avoid ordinal artifacts.
6. **Synthetic dataset** — validates behavior; says nothing about real traffic
   distributions.
7. **Categorical features dominate the Euclidean geometry** — by design ([§10](#10-feature-geometry)),
   any single categorical mismatch alone exceeds epsilon, so numeric differences
   only separate records *within* an agreeing group. Useful here, but it means the
   model is nearly blind to numeric-only anomalies inside a dense profile.

## 24. Experiments to try

The fastest way to *understand* DBSCAN is to watch it break. Each experiment below
changes one thing — observe cluster count, noise count, and which records flip.

| Experiment | What to watch |
|---|---|
| **Increase `epsilon`** (0.35 → 0.5 → 1.0) | more points become neighbors → clusters may merge → noise usually decreases; too far and distinct profiles fuse into one blob |
| **Decrease `epsilon`** | neighborhoods shrink → clusters fragment → noise increases until nearly everything is noise |
| **Increase `minimum_samples`** (5 → 10 → 20) | harder to form dense regions → small clusters dissolve into noise |
| **Change endpoint buckets** (`endpoint_hash_buckets`: 8 / 16 / 64) | fewer buckets → more collisions, distinct endpoints merge; more buckets → the same physical endpoint set spreads over more dimensions (still √2 between any two buckets — geometry is bucket-count-invariant) |
| **Remove one feature** from `FeatureExtractor` | e.g. drop `hour`: does slowloris still separate? The answer tells you which feature carries which anomaly kind |
| **Replace min-max with Z-score** | outlier sensitivity changes; watch how slow requests and payload floods re-rank |
| **Change the dataset distribution** (`scripts/generate_dataset.php`) | e.g. make one "anomaly kind" 10% of traffic — it becomes dense and *stops being flagged*: the noise→anomaly heuristic only sees rarity, not intent |
| **Introduce more anomaly kinds** | watch noise count vs per-kind density; inject enough of one kind and it earns its own cluster |
| **Compare geometry by hand** | compute √2 vs epsilon for two records differing in exactly one categorical block — verify against `FeatureGeometryTest` |

Experiment 3 in the "distribution" row is the deepest lesson in this README:
**the algorithm measures density; only your interpretation decides what density
means.**

## 25. Roadmap

- [x] **V1** — boilerplate removal, SQLite, CSV dataset, domain model, feature extraction, encoding, normalization, DBSCAN, tests
- [x] **V2** — analysis persistence, `/health`, `/analyze`, typed per-endpoint error handling
- [x] **V2.5** — dashboard, atomic persistence with FK integrity, Docker
- [x] **V3** — Nginx `access.log` parser, batch analysis CLI (`php runway analyze`)
- [ ] **V4** — DBSCAN vs K-Means (and other PHP-ML techniques) compared with metrics that fit each family (density-based vs centroid-based)
- [ ] **V5** — benchmarking, statistics, advanced visualization

No deployment/infrastructure roadmap — this is a study project.

## License

[MIT](LICENSE)
