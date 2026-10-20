#!/usr/bin/env bash
#
# Replace the TEXTY_VERSION placeholder with the current plugin version.
#
# TEXTY_VERSION placeholder in source files (typically `@since TEXTY_VERSION`
# docblocks on newly added code) is replaced with the "version" value from
# package.json. Run on release via `npm run version`.
#
# Usage: bash bin/version-replace.sh
#
set -euo pipefail

# Resolve plugin root (parent of this script's directory) and work from there.
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

PLACEHOLDER="TEXTY_VERSION"

# Only replace the placeholder inside docblock tags. TEXTY_VERSION is also a
# live PHP constant (texty.php, Install.php, Menu.php, Migrations.php), so a
# bare global replace would corrupt those — match @since/@deprecated/@version.
TAGS="@(since|deprecated|version)"

# Read "version" from package.json.
VERSION="$(grep -m1 '"version"' package.json | sed -E 's/.*"version"[[:space:]]*:[[:space:]]*"([^"]+)".*/\1/')"

if [ -z "$VERSION" ]; then
    echo "Error: could not read version from package.json" >&2
    exit 1
fi

echo "Replacing ${PLACEHOLDER} -> ${VERSION}"

# List source files with the placeholder in a docblock tag, skipping build/third-party trees.
files="$(grep -rlE "${TAGS}[[:space:]]+${PLACEHOLDER}" . \
    --include='*.php' --include='*.js' --include='*.jsx' \
    --include='*.ts' --include='*.tsx' --include='*.scss' --include='*.css' \
    --exclude-dir={node_modules,vendor,lib,build,dist,.git} || true)"

count=0
for file in $files; do
    # Replace only the placeholder that follows a docblock tag; leave the
    # TEXTY_VERSION constant references intact.
    perl -pi -e "s/(${TAGS}\s+)\Q${PLACEHOLDER}\E\b/\${1}${VERSION}/g" "$file"
    echo "  updated: $file"
    count=$((count + 1))
done

echo "Done. ${count} file(s) updated."
