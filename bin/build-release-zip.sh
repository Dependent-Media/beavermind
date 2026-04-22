#!/usr/bin/env bash
# Build a distributable BeaverMind plugin zip.
#
# Usage:   bash bin/build-release-zip.sh [tag-name]
# Output:  dist/beavermind-<tag>.zip
#
# The zip is structured as wp-content/plugins/beavermind/* — drop it into
# WordPress admin → Plugins → Add New → Upload Plugin and it just works.
# vendor/ is bundled (composer install --no-dev should have run first).
#
# Excluded from the zip (developer-only):
#   .git/, .github/, .gitignore
#   _TestRunner/ (macOS dev tool)
#   tests/playwright/ (E2E project, with secrets / node_modules)
#   bin/, dist/
#   composer.json (composer.lock kept for reproducibility)
#   docs/, .env*, .auth/

set -euo pipefail

TAG="${1:-dev-$(date +%Y%m%d-%H%M%S)}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST="$HERE/dist"
STAGING="$DIST/staging/beavermind"
ZIP_PATH="$DIST/beavermind-${TAG}.zip"

rm -rf "$DIST/staging" "$ZIP_PATH"
mkdir -p "$STAGING"

# Use rsync with exclude rules so the staging copy is exactly what ships.
rsync -a \
  --exclude='/.git/' \
  --exclude='/.github/' \
  --exclude='/.gitignore' \
  --exclude='/_TestRunner/' \
  --exclude='/tests/playwright/' \
  --exclude='/bin/' \
  --exclude='/dist/' \
  --exclude='/docs/' \
  --exclude='/.env*' \
  --exclude='/.auth/' \
  --exclude='/.DS_Store' \
  --exclude='**/.DS_Store' \
  --exclude='**/node_modules/' \
  --exclude='/composer.json' \
  --exclude='/.phpunit.result.cache' \
  --exclude='/playwright-report/' \
  --exclude='/test-results/' \
  "$HERE/" "$STAGING/"

# Sanity: make sure vendor/ made it (composer install --no-dev must run before
# this script in the release workflow).
if [ ! -d "$STAGING/vendor" ]; then
  echo "::error::vendor/ missing from staging — run 'composer install --no-dev' before build-release-zip.sh" >&2
  exit 1
fi

# Sanity: don't ship secrets if a developer accidentally left files.
if find "$STAGING" -type f -name '.env' -print -quit | grep -q .; then
  echo "::error::Found .env in staging — refusing to ship" >&2
  exit 1
fi

cd "$DIST/staging"
zip -qr "$ZIP_PATH" beavermind

echo
echo "Built: $ZIP_PATH"
ls -lh "$ZIP_PATH"
echo "Contents (top level):"
# Materialize the full sorted listing first, then head it. Piping
# `... | sort | head` with `set -o pipefail` SIGPIPEs sort and aborts.
LISTING="$(unzip -l "$ZIP_PATH" | awk 'NR>3 && NF>=4 {print "  " $4}' | sort -u)"
printf '%s\n' "$LISTING" | head -30
