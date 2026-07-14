#!/usr/bin/env bash
# Builds dist/kilden.zip — the installable plugin, honoring .distignore.
set -euo pipefail

cd "$(dirname "$0")/.."
VERSION="${1:-dev}"

rm -rf dist/kilden dist/kilden.zip
mkdir -p dist/kilden

rsync -a --exclude-from=.distignore ./ dist/kilden/

(cd dist && zip -qr kilden.zip kilden)
echo "dist/kilden.zip built (${VERSION})"
unzip -l dist/kilden.zip | tail -3
