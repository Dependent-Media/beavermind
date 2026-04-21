#!/usr/bin/env bash
# Cut a new release locally:
#   1. Bump the plugin header Version: and BEAVERMIND_VERSION constant
#   2. Commit the bump
#   3. Tag and push
#   4. Trigger the GitHub Release workflow via the tag push
#
# Usage:  bash bin/release.sh <new-version>
# Example: bash bin/release.sh 0.2.0  →  tag v0.2.0
set -euo pipefail

if [ $# -lt 1 ]; then
  echo "Usage: $0 <new-version>   (e.g. $0 0.2.0)" >&2
  exit 1
fi
NEW="$1"
TAG="v${NEW}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$HERE"

# Refuse to release with uncommitted changes.
if ! git diff --quiet || ! git diff --cached --quiet; then
  echo "Uncommitted changes — commit or stash first." >&2
  exit 1
fi

# Refuse to release from a non-main branch (sanity check).
BRANCH="$(git branch --show-current)"
if [ "$BRANCH" != "main" ]; then
  echo "Not on main (currently on $BRANCH). Aborting." >&2
  exit 1
fi

# Update the two version anchors.
sed -i.bak -E "s|^( \\* Version: *)([0-9]+\\.[0-9]+\\.[0-9]+).*$|\\1${NEW}|" beavermind.php
sed -i.bak -E "s|(define\\( *'BEAVERMIND_VERSION', *')([^']+)(' *\\);)|\\1${NEW}\\3|" beavermind.php
rm -f beavermind.php.bak

git add beavermind.php
git commit -m "Release ${TAG}"
git tag -a "${TAG}" -m "Release ${TAG}"
git push origin main
git push origin "${TAG}"

echo
echo "Tagged ${TAG} and pushed. The Release workflow will:"
echo "  1. composer install --no-dev"
echo "  2. bash bin/build-release-zip.sh ${TAG}"
echo "  3. gh release create ${TAG} dist/beavermind-${TAG}.zip --generate-notes"
