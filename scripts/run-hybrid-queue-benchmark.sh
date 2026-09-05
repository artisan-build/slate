#!/usr/bin/env bash

set -Eeuo pipefail

RUN_COUNT="${1:-3}"
RESULTS_PATH="${2:-}"

if [[ ! "$RUN_COUNT" =~ ^[1-9][0-9]*$ ]]; then
    echo "Run count must be a positive integer." >&2
    exit 2
fi

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RESULTS_PATH="${RESULTS_PATH:-$ROOT/storage/app/benchmark-results/hybrid-queue-$(date -u +%Y%m%dT%H%M%SZ).log}"
PREFIX="slate-hybrid-$$"
NETWORK="${PREFIX}-network"
DATABASE="${PREFIX}-db"
BUCKET="${PREFIX}-bucket"
DISPATCHER="${PREFIX}-dispatcher"
APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='
CONTAINERS=("$DATABASE" "$BUCKET" "$DISPATCHER")

mkdir -p "$(dirname "$RESULTS_PATH")"
: > "$RESULTS_PATH"

cleanup() {
    docker rm --force "${CONTAINERS[@]}" >/dev/null 2>&1 || true
    docker network rm "$NETWORK" >/dev/null 2>&1 || true
}

trap cleanup EXIT INT TERM

wait_for_log() {
    local container="$1"
    local pattern="$2"

    for _ in {1..180}; do
        if docker logs "$container" 2>&1 | grep -q "$pattern"; then
            return
        fi

        sleep 1
    done

    echo "Timed out waiting for ${container}: ${pattern}" >&2
    docker logs "$container" >&2 || true
    exit 1
}

APP_ENV=(
    --env "APP_KEY=$APP_KEY"
    --env DB_CONNECTION=pgsql
    --env DB_URL=
    --env "DB_HOST=$DATABASE"
    --env DB_PORT=5432
    --env DB_DATABASE=slate
    --env DB_USERNAME=slate
    --env DB_PASSWORD=slate-pass
    --env QUEUE_CONNECTION=database
    --env DB_QUEUE_RETRY_AFTER=180
    --env FILESYSTEM_DISK=s3
    --env AWS_ACCESS_KEY_ID=slate-minio
    --env AWS_SECRET_ACCESS_KEY=slate-minio-secret
    --env AWS_DEFAULT_REGION=us-east-1
    --env AWS_BUCKET=slate-tests
    --env "AWS_ENDPOINT=http://${BUCKET}:9000"
    --env AWS_USE_PATH_STYLE_ENDPOINT=true
)

docker info >/dev/null
RUNNING_CONTAINERS="$(docker ps --format '{{.Names}}')"

if [[ -n "$RUNNING_CONTAINERS" ]]; then
    echo "Refusing to measure while other containers are running:" >&2
    echo "$RUNNING_CONTAINERS" >&2
    exit 1
fi

docker network create "$NETWORK" >/dev/null

docker run --detach --name "$DATABASE" --network "$NETWORK" \
    --env POSTGRES_DB=slate \
    --env POSTGRES_USER=slate \
    --env POSTGRES_PASSWORD=slate-pass \
    postgres:17 >/dev/null

docker run --detach --name "$BUCKET" --network "$NETWORK" \
    --env MINIO_ROOT_USER=slate-minio \
    --env MINIO_ROOT_PASSWORD=slate-minio-secret \
    minio/minio:latest server /data >/dev/null

for _ in {1..120}; do
    if docker exec "$DATABASE" pg_isready -U slate -d slate >/dev/null 2>&1; then
        break
    fi

    sleep 1
done

docker exec "$DATABASE" pg_isready -U slate -d slate >/dev/null
wait_for_log "$BUCKET" 'API:'

docker run --rm --platform linux/arm64 --user root --network "$NETWORK" \
    "${APP_ENV[@]}" \
    --volume "$ROOT:/src:ro" \
    serversideup/php:8.4-cli \
    sh -c 'cp -a /src /app && cd /app && php artisan migrate --force' >/dev/null

for worker in {1..4}; do
    name="${PREFIX}-worker-${worker}"
    CONTAINERS+=("$name")

    docker run --detach --name "$name" --hostname "slate-worker-${worker}" \
        --cpus=1 --memory=2g --platform linux/arm64 --user root --network "$NETWORK" \
        "${APP_ENV[@]}" \
        --volume "$ROOT:/src:ro" \
        serversideup/php:8.4-cli \
        sh -c 'apt-get update -qq && apt-get install -y -qq fontconfig fonts-dejavu-core >/dev/null 2>&1; cp -a /src /app && cd /app; php artisan slate:provision-ffmpeg --force; php artisan queue:work --sleep=1 --timeout=170 --tries=3' >/dev/null
done

docker run --detach --name "$DISPATCHER" --hostname slate-dispatcher \
    --cpus=1 --memory=2g --platform linux/arm64 --user root --network "$NETWORK" \
    "${APP_ENV[@]}" \
    --volume "$ROOT:/src:ro" \
    serversideup/php:8.4-cli \
    sh -c 'apt-get update -qq && apt-get install -y -qq fontconfig fonts-dejavu-core >/dev/null 2>&1; cp -a /src /app && cd /app; php artisan slate:provision-ffmpeg --force; sleep infinity' >/dev/null

for worker in {1..4}; do
    wait_for_log "${PREFIX}-worker-${worker}" 'ffmpeg provisioning complete'
done

wait_for_log "$DISPATCHER" 'ffmpeg provisioning complete'

echo "Capturing benchmark output in $RESULTS_PATH"
echo "Running ${RUN_COUNT} production-size queued comparison(s) against four workers." | tee -a "$RESULTS_PATH"

for run in $(seq 1 "$RUN_COUNT"); do
    arms='single,fanout'

    if (( run % 2 == 0 )); then
        arms='fanout,single'
    fi

    echo "Measurement ${run}/${RUN_COUNT}; arm order ${arms}; video counts 1,3,5." | tee -a "$RESULTS_PATH"
    docker exec --workdir /app \
        --env SLATE_REQUIRE_MULTI_WORKER=1 \
        --env "SLATE_BENCHMARK_ARMS=$arms" \
        --env SLATE_BENCHMARK_VIDEO_COUNTS=1,3,5 \
        "$DISPATCHER" \
        vendor/bin/pest tests/Feature/Services/HybridQueueBenchmarkTest.php --group=hybrid-queue-benchmark \
        | tee -a "$RESULTS_PATH"
done

# This host's measured ceiling is four 1-vCPU/2-GiB workers. Any 10-worker figure derived from these
# records is extrapolated and must be labelled EXTRAPOLATED.
