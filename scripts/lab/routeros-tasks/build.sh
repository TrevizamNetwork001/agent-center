#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")/../../.."
# Clean Linux amd64/glibc install from lockfile; never reuse host node_modules.
build_state=${ROUTEROS_BUILD_STATE:-/tmp/agent-center-routeros-build-v1}
test ! -e "$build_state"
mkdir -p "$build_state"
docker run --rm --platform linux/amd64 --mount "type=bind,src=$PWD/app,dst=/source,readonly" \
  --mount "type=bind,src=$build_state,dst=/build" --workdir /build node:24-bookworm-slim \
  sh -c 'cp /source/package.json /source/package-lock.json /source/vite.config.js . && cp -R /source/resources . && mkdir -p public && npm ci --include=optional --ignore-scripts && npm run build'
