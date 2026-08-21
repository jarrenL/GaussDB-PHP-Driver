#!/usr/bin/env bash
set -euo pipefail

image=${GAUSSDB_IMAGE:-gaussdb507-arm64:507.0.0}
volume=${GAUSSDB_DATA_VOLUME:-gaussdb507-data}
platform=${GAUSSDB_PLATFORM:-linux/arm64}

docker image inspect "$image" >/dev/null || {
  echo "Missing Docker image: $image" >&2
  exit 1
}
docker run --rm --platform "$platform" --entrypoint /bin/sh "$image" \
  -c 'test -x /usr/sbin/chroot' || {
    echo "Image $image does not provide /usr/sbin/chroot required by gaussdb-entrypoint.sh" >&2
    exit 1
  }
docker volume inspect "$volume" >/dev/null || {
  echo "Missing external Docker volume: $volume" >&2
  exit 1
}
if docker run --rm --platform "$platform" \
  -v "$volume:/gaussdb-data:ro" \
  --entrypoint /bin/sh "$image" \
  -c 'test -f /gaussdb-data/PG_VERSION'; then
  echo "GaussDB image and initialized data volume are ready"
  exit 0
fi

if [[ ${GAUSSDB_ALLOW_EMPTY_DATA:-0} == 1 ]]; then
  docker run --rm --platform "$platform" \
    -v "$volume:/gaussdb-data:ro" \
    --entrypoint /bin/sh "$image" \
    -c 'test -z "$(find /gaussdb-data -mindepth 1 -maxdepth 1 -print -quit)"' || {
      echo "Volume $volume is neither initialized nor empty; refusing to overwrite it" >&2
      exit 1
    }
  echo "GaussDB image and empty data volume are ready for first-start initialization"
  exit 0
fi

echo "Volume $volume is not initialized. Set GAUSSDB_ALLOW_EMPTY_DATA=1 only for a verified empty volume." >&2
exit 1
