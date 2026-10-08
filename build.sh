#!/usr/bin/env bash
#
# Build a release of the KioskPay WordPress plugin.
#
#   ./build.sh              use the version already in the plugin header
#   ./build.sh 0.1.4        stamp 0.1.4 into the header + MALIPO_VERSION, then build
#   ./build.sh --no-lint    skip php -l (e.g. when PHP is not installed)
#
# Steps: lint every PHP file -> stamp the version -> zip the plugin -> write the
# update manifest. Outputs, in dist/:
#
#   malipo-payments-<version>.zip   the plugin, ready to upload
#   malipo-payments.json            the self-update manifest, ready to upload
#
# Upload both to https://kioskpay.co.ke/ to publish a release.

set -euo pipefail

DIR="$(cd "$(dirname "$0")" && pwd)"
PLUGIN="$DIR/malipo-payments.php"
DIST="$DIR/dist"
TEMPLATE="$DIR/manifest.json"

VERSION=""
LINT=1
for arg in "$@"; do
  case "$arg" in
    --no-lint) LINT=0 ;;
    --*) echo "unknown option: $arg" >&2; exit 2 ;;
    *) VERSION="$arg" ;;
  esac
done

# 1. Version: an argument wins, otherwise read the plugin header.
if [ -z "$VERSION" ]; then
  VERSION="$(sed -n -E 's/^ \* Version: +([0-9]+\.[0-9]+\.[0-9]+).*/\1/p' "$PLUGIN" | head -1)"
fi
if ! printf '%s' "$VERSION" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+$'; then
  echo "error: version must look like 1.2.3 (got '${VERSION:-}')" >&2
  exit 2
fi

# 2. Lint before touching anything, so a bad build leaves the tree unchanged.
if [ "$LINT" = "1" ]; then
  if ! command -v php >/dev/null 2>&1; then
    echo "error: php not found; install PHP or rerun with --no-lint" >&2
    exit 1
  fi
  echo "==> linting PHP"
  while IFS= read -r file; do
    php -l "$file" >/dev/null
  done < <(find "$DIR/includes" "$PLUGIN" -name '*.php' -type f)
  echo "    ok"
fi

# 3. Stamp the version into the plugin header and MALIPO_VERSION.
sed -E "s/^( \* Version: +)[0-9]+\.[0-9]+\.[0-9]+/\1$VERSION/" "$PLUGIN" > "$PLUGIN.tmp"
mv "$PLUGIN.tmp" "$PLUGIN"
sed -E "s/define\( 'MALIPO_VERSION', '[^']*' \);/define( 'MALIPO_VERSION', '$VERSION' );/" "$PLUGIN" > "$PLUGIN.tmp"
mv "$PLUGIN.tmp" "$PLUGIN"
echo "==> version $VERSION"

# 4. Stage and zip (top-level malipo-payments/ so WordPress installs it cleanly).
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$STAGE/malipo-payments"
cp "$PLUGIN" "$DIR/README.md" "$STAGE/malipo-payments/"
cp -R "$DIR/includes" "$DIR/assets" "$STAGE/malipo-payments/"

mkdir -p "$DIST"
ZIP="$DIST/malipo-payments-$VERSION.zip"
rm -f "$ZIP"
( cd "$STAGE" && zip -r -X -q "$ZIP" malipo-payments )
echo "==> $ZIP"

# 5. Manifest from the template.
if [ -f "$TEMPLATE" ]; then
  sed "s/__VERSION__/$VERSION/g" "$TEMPLATE" > "$DIST/malipo-payments.json"
  echo "==> $DIST/malipo-payments.json"
else
  echo "warning: $TEMPLATE not found; no manifest written" >&2
fi

echo "==> done — upload dist/malipo-payments-$VERSION.zip and dist/malipo-payments.json to kioskpay.co.ke"
