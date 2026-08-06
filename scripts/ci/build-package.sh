#!/usr/bin/env bash
set -euo pipefail

PLUGIN_SLUG="onesmtp"
OUTPUT_DIR="${1:-dist}"

if [[ ! -f "build/index.js" || ! -f "build/index.asset.php" || ! -f "build/dataviews.css" ]]; then
	echo "Compiled admin assets are missing. Run npm run build before packaging." >&2
	exit 1
fi

mkdir -p "$OUTPUT_DIR"
OUTPUT_DIR="$(cd "$OUTPUT_DIR" && pwd)"

ZIP_PATH="$OUTPUT_DIR/$PLUGIN_SLUG.zip"
CHECKSUM_PATH="$ZIP_PATH.sha256"
if [[ -e "$ZIP_PATH" || -e "$CHECKSUM_PATH" ]]; then
	echo "Package output already exists: $ZIP_PATH" >&2
	exit 1
fi

STAGING_DIR="$(mktemp -d)"
trap 'rm -rf "$STAGING_DIR"' EXIT
mkdir -p "$STAGING_DIR/$PLUGIN_SLUG"

rsync -a ./ "$STAGING_DIR/$PLUGIN_SLUG/" \
	--exclude ".git" \
	--exclude ".github" \
	--exclude ".phpstan" \
	--exclude ".phpunit.cache" \
	--exclude ".editorconfig" \
	--exclude ".gitignore" \
	--exclude ".idea" \
	--exclude ".vscode" \
	--exclude "node_modules" \
	--exclude "vendor" \
	--exclude "tests" \
	--exclude "artifacts" \
	--exclude "dist" \
	--exclude "output" \
	--exclude "coverage" \
	--exclude "scripts" \
	--exclude "docs" \
	--exclude "package.json" \
	--exclude "package-lock.json" \
	--exclude "playwright.config.js" \
	--exclude "composer.json" \
	--exclude "composer.lock" \
	--exclude "phpcs*.xml*" \
	--exclude "phpunit.xml.dist" \
	--exclude "phpstan.neon" \
	--exclude "AGENTS.md" \
	--exclude "CONTRIBUTING.md" \
	--exclude "DESIGN.md" \
	--exclude "RELEASE.md" \
	--exclude "TESTING.md"

(cd "$STAGING_DIR" && zip -qr "$ZIP_PATH" "$PLUGIN_SLUG")
shasum -a 256 "$ZIP_PATH" > "$CHECKSUM_PATH"
shasum -a 256 -c "$CHECKSUM_PATH"

ENTRY_MANIFEST="$STAGING_DIR/package-entries.txt"
unzip -Z1 "$ZIP_PATH" > "$ENTRY_MANIFEST"

grep -qx "$PLUGIN_SLUG/build/index.js" "$ENTRY_MANIFEST"
grep -qx "$PLUGIN_SLUG/build/index.asset.php" "$ENTRY_MANIFEST"
grep -qx "$PLUGIN_SLUG/build/dataviews.css" "$ENTRY_MANIFEST"

for excluded in node_modules vendor tests artifacts dist output coverage scripts docs .git .github .phpstan .phpunit.cache package.json package-lock.json composer.json composer.lock playwright.config.js; do
	if grep -Eq "^$PLUGIN_SLUG/$excluded(?:/|$)" "$ENTRY_MANIFEST"; then
		echo "Development-only package entry found: $excluded" >&2
		exit 1
	fi
done

echo "Package verified: $ZIP_PATH"
