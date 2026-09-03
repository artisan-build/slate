#!/usr/bin/env bash

set -Eeuo pipefail

WORKER_COUNT="${1:-4}"
VIDEO_COUNT="${2:-1}"
RUN_COUNT="${3:-3}"

case "$WORKER_COUNT" in
    1|2|4) ;;
    *)
        echo "Worker count must be 1, 2, or 4." >&2
        exit 2
        ;;
esac

for value in "$VIDEO_COUNT" "$RUN_COUNT"; do
    if [[ ! "$value" =~ ^[1-9][0-9]*$ ]]; then
        echo "Video and run counts must be positive integers." >&2
        exit 2
    fi
done

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PREFIX="slate-fanout-$$"
NETWORK="${PREFIX}-network"
DATABASE="${PREFIX}-db"
BUCKET="${PREFIX}-bucket"
DISPATCHER="${PREFIX}-dispatcher"
APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='
CONTAINERS=("$DATABASE" "$BUCKET" "$DISPATCHER")

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
    --env DB_CONNECTION=mysql
    --env "DB_HOST=$DATABASE"
    --env DB_PORT=3306
    --env DB_DATABASE=slate
    --env DB_USERNAME=slate
    --env DB_PASSWORD=slate-pass
    --env QUEUE_CONNECTION=database
    --env FILESYSTEM_DISK=s3
    --env AWS_ACCESS_KEY_ID=slate-minio
    --env AWS_SECRET_ACCESS_KEY=slate-minio-secret
    --env AWS_DEFAULT_REGION=us-east-1
    --env AWS_BUCKET=slate-tests
    --env "AWS_ENDPOINT=http://${BUCKET}:9000"
    --env AWS_USE_PATH_STYLE_ENDPOINT=true
)

docker info >/dev/null
docker network create "$NETWORK" >/dev/null

docker run --detach --name "$DATABASE" --network "$NETWORK" \
    --env MYSQL_ROOT_PASSWORD=slate-root \
    --env MYSQL_DATABASE=slate \
    --env MYSQL_USER=slate \
    --env MYSQL_PASSWORD=slate-pass \
    mysql:8.4 >/dev/null

docker run --detach --name "$BUCKET" --network "$NETWORK" \
    --env MINIO_ROOT_USER=slate-minio \
    --env MINIO_ROOT_PASSWORD=slate-minio-secret \
    minio/minio:latest server /data >/dev/null

for _ in {1..120}; do
    if docker exec "$DATABASE" mysqladmin ping -h 127.0.0.1 -uslate -pslate-pass --silent >/dev/null 2>&1; then
        break
    fi

    sleep 1
done

docker exec "$DATABASE" mysqladmin ping -h 127.0.0.1 -uslate -pslate-pass --silent >/dev/null
wait_for_log "$BUCKET" 'API:'

docker run --rm --platform linux/arm64 --user root --network "$NETWORK" \
    "${APP_ENV[@]}" \
    --volume "$ROOT:/src:ro" \
    serversideup/php:8.4-cli \
    sh -c 'cp -a /src /app && cd /app && php artisan migrate --force' >/dev/null

for worker in $(seq 1 "$WORKER_COUNT"); do
    name="${PREFIX}-worker-${worker}"
    CONTAINERS+=("$name")

    docker run --detach --name "$name" --hostname "slate-worker-${worker}" \
        --cpus=1 --memory=2g --platform linux/arm64 --user root --network "$NETWORK" \
        "${APP_ENV[@]}" \
        --volume "$ROOT:/src:ro" \
        serversideup/php:8.4-cli \
        sh -c 'apt-get update -qq && apt-get install -y -qq fontconfig fonts-dejavu-core >/dev/null 2>&1; cp -a /src /app && cd /app; php artisan slate:provision-ffmpeg --force; php artisan queue:work --sleep=1 --timeout=60 --tries=3' >/dev/null
done

docker run --detach --name "$DISPATCHER" --hostname slate-dispatcher \
    --cpus=1 --memory=2g --platform linux/arm64 --user root --network "$NETWORK" \
    "${APP_ENV[@]}" \
    --volume "$ROOT:/src:ro" \
    serversideup/php:8.4-cli \
    sh -c 'apt-get update -qq && apt-get install -y -qq fontconfig fonts-dejavu-core >/dev/null 2>&1; cp -a /src /app && cd /app; php artisan slate:provision-ffmpeg --force; sleep infinity' >/dev/null

for worker in $(seq 1 "$WORKER_COUNT"); do
    wait_for_log "${PREFIX}-worker-${worker}" 'ffmpeg provisioning complete'
done

wait_for_log "$DISPATCHER" 'ffmpeg provisioning complete'

echo "Running ${RUN_COUNT} measured run(s): ${VIDEO_COUNT} video(s), ${WORKER_COUNT} worker(s)."

for run in $(seq 1 "$RUN_COUNT"); do
    echo "Measurement ${run}/${RUN_COUNT}"
    docker exec --workdir /app \
        --env SLATE_REQUIRE_MULTI_WORKER=1 \
        --env "SLATE_FANOUT_VIDEO_COUNT=$VIDEO_COUNT" \
        --env "SLATE_EXPECT_DISTINCT_WORKERS=$WORKER_COUNT" \
        "$DISPATCHER" \
        vendor/bin/pest tests/Feature/Services/FanoutMultiWorkerTest.php --group=fanout-multi-worker
done

# Reproduce the measured curve with:
#   scripts/run-fanout-multi-worker.sh 1 1 3
#   scripts/run-fanout-multi-worker.sh 2 1 3
#   scripts/run-fanout-multi-worker.sh 4 1 3
#   scripts/run-fanout-multi-worker.sh 4 5 3
# This 8-core/16-GB host cannot honestly run the 10-worker/50-video saturation case.
