#!/usr/bin/env bash
# Build only the two explicitly selected diagnostic profiles. Never publish.
set -euo pipefail
if [[ $# != 2 || "$1" != '--output' ]]; then
  echo 'Usage: bash tools/php-wasm-runtime/rebuild.sh --output /tmp/new-directory' >&2
  exit 2
fi
script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "$script_dir/../.." && pwd)"
output="$(python3 - "$2" "$repo_root" <<'PY'
import pathlib, sys, tempfile
p = pathlib.Path(sys.argv[1]).absolute()
parent = p.parent.resolve(strict=True)
tmp = pathlib.Path(tempfile.gettempdir()).resolve()
root = pathlib.Path(sys.argv[2]).resolve()
if not parent.is_relative_to(tmp) or (parent / p.name).is_relative_to(root):
    raise SystemExit('Output must be outside the checkout beneath the OS temporary root')
p = parent / p.name
p.mkdir()  # Refuse an existing destination or symlink.
print(p)
PY
)"
mkdir "$output/artifacts"
upstream="$output/upstream"
git init -q "$upstream"
timeout -k 10s 300s git -C "$upstream" fetch --depth=1 https://github.com/WordPress/wordpress-playground.git a6ed3872674399baa47c2f55fe1e660633fc8051
git -C "$upstream" checkout -q --detach FETCH_HEAD
compile="$upstream/packages/php-wasm/compile"
# Exact source/library Git object identities, not a claim that all libraries were rebuilt.
git -C "$upstream" ls-tree -r HEAD packages/php-wasm/compile > "$output/artifacts/upstream-tree.txt"
python3 "$script_dir/prepare.py" --upstream "$upstream" > "$output/artifacts/baseline-build.json"
cp "$compile/php/Dockerfile" "$output/artifacts/baseline.Dockerfile"
cp "$compile/base-image/Dockerfile" "$output/artifacts/base.Dockerfile"
timeout -k 20s 900s docker build --progress=plain -f "$compile/base-image/Dockerfile" -t playground-php-wasm:base "$compile/base-image"
docker image inspect --format '{{.Id}}' playground-php-wasm:base > "$output/artifacts/base-image-id.txt"

container=''
cleanup() { if [[ -n "$container" ]]; then docker rm -f "$container" >/dev/null; fi; }
trap cleanup EXIT
build_profile() {
  local profile="$1"
  local destination="$output/artifacts/$profile"
  mkdir -p "$destination/asyncify"
  local image="ppphp-php-wasm:$profile"
  local -a flags=(
    PHP_VERSION=8.4.23 PHP_REF=php-8.4.23 WITH_JSPI=no
    WITH_FILEINFO=yes WITH_LIBXML=yes WITH_SOAP=yes WITH_LIBZIP=yes
    WITH_EXIF=yes WITH_GD=yes WITH_MBSTRING=yes WITH_MBREGEX=yes
    WITH_CLI_SAPI=yes WITH_OPENSSL=yes OPENSSL_VERSION=1.1.0h
    WITH_NODEFS=no WITH_CURL=yes WITH_SQLITE=yes WITH_SOURCEMAPS=no
    WITH_DEBUG=no WITH_ICONV=yes WITH_MYSQL=no WITH_WS_NETWORKING_PROXY=yes
    WITH_IMAGICK=no EMSCRIPTEN_ENVIRONMENT=web WITH_OPCACHE=yes STACK_SIZE=1MB
    OUTPUT_DIR_ON_HOST=/diagnostic DEBUG_DWARF_COMPILATION_DIR=/diagnostic
  )
  local -a command=(docker build --progress=plain -f "$compile/php/Dockerfile" --tag "$image")
  for flag in "${flags[@]}"; do command+=(--build-arg "$flag"); done
  command+=("$upstream/packages/php-wasm")
  printf '%s\n' "${flags[@]}" > "$destination/build-arguments.txt"
  timeout -k 20s 1200s "${command[@]}"
  container="$(docker create --network none "$image")"
  docker cp "$container:/root/output/." "$destination/asyncify/"
  docker cp "$container:/root/php-src/Zend/zend_fibers.c" "$destination/zend_fibers.c"
  docker cp "$container:/root/.emcc-php-asyncify-flags" "$destination/asyncify-flags.txt"
  docker cp "$container:/root/.emcc-php-wasm-flags" "$destination/link-flags.txt"
  docker rm "$container" >/dev/null; container=''
  docker image inspect --format '{{.Id}}' "$image" > "$destination/image-id.txt"
  # Selected provenance only; do not capture arbitrary environment variables.
  docker run --rm --network none "$image" bash -lc 'source /root/emsdk/emsdk_env.sh >/dev/null; emcc --version; git -C /root/php-src rev-parse HEAD; git -C /root/emsdk rev-parse HEAD' > "$destination/toolchain.txt"
  python3 - "$destination" <<'PY'
import hashlib, json, pathlib, sys
root = pathlib.Path(sys.argv[1])
files = []
for p in sorted(root.rglob('*')):
    if p.is_symlink(): raise SystemExit('Unexpected output symlink')
    if p.is_file():
        if p.stat().st_size > 256 * 1024 * 1024: raise SystemExit('Oversized diagnostic artifact')
        files.append({'path':p.relative_to(root).as_posix(), 'bytes':p.stat().st_size, 'sha256':hashlib.sha256(p.read_bytes()).hexdigest()})
(root / 'artifacts.json').write_text(json.dumps({'profile':root.name, 'productionReady':False, 'files':files}, indent=2)+'\n')
PY
  node "$script_dir/verify-built.mjs" --runtime "$destination" --expect "$profile" --output "$output/$profile-evidence"
  cp "$output/$profile-evidence/report.json" "$output/artifacts/$profile-report.json"
}
build_profile baseline
# Do not risk a second build if the symbolized baseline itself is not reproduced.
python3 "$script_dir/prepare.py" --upstream "$upstream" --candidate > "$output/artifacts/candidate-build.json"
cp "$compile/php/Dockerfile" "$output/artifacts/candidate.Dockerfile"
build_profile candidate
