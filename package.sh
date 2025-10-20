#!/usr/bin/env bash
set -euo pipefail

# Package the Big Storm Staging plugin into a zip that expands into a
# top-level "bigstorm-stage" directory, excluding .gitignore and common junk.
#
# Output: dist/bigstorm-stage-<version>.zip
# Usage: ./package.sh

# Resolve script directory (repo root)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

SLUG="bigstorm-stage"
DIST_DIR="$SCRIPT_DIR/dist"
BUILD_DIR="$DIST_DIR/$SLUG"

# Extract version from plugin header; fallback to "dev" if not found
VERSION=$(awk -F: '/^[ \t]*\*[ \t]*Version[ \t]*:/ {gsub(/^[ \t]+|\r|\n/ ,"",$2); print $2; exit}' "$SCRIPT_DIR/$SLUG.php" || true)
VERSION=${VERSION:-dev}
VERSION=$(echo "$VERSION" | sed -e 's/^v//')

# Clean build dir
rm -rf "$BUILD_DIR"
mkdir -p "$BUILD_DIR"

# Copy files to build dir, excluding VCS and build artifacts
# Prefer rsync for correctness on macOS/Linux
if command -v rsync >/dev/null 2>&1; then
  rsync -a ./ "$BUILD_DIR" \
    --delete \
    --exclude ".git/" \
    --exclude ".github/" \
    --exclude ".gitignore" \
    --exclude ".DS_Store" \
    --exclude "dist/" \
    --exclude "node_modules/" \
    --exclude "vendor/" \
    --exclude "*.zip" \
    --exclude "package.sh"
else
  # Fallback: use tar to copy, then remove excluded files
  tar -cf - . | (cd "$BUILD_DIR" && tar -xf -)
  rm -rf "$BUILD_DIR/.git" "$BUILD_DIR/.github" "$BUILD_DIR/dist" "$BUILD_DIR/node_modules" "$BUILD_DIR/vendor" || true
  rm -f "$BUILD_DIR/.gitignore" "$BUILD_DIR/.DS_Store" "$BUILD_DIR/package.sh" || true
  find "$BUILD_DIR" -name "*.zip" -delete || true
fi

# Create dist directory
mkdir -p "$DIST_DIR"
ZIP_PATH="$DIST_DIR/$SLUG-$VERSION.zip"

# Create the zip so that it expands into the "$SLUG" folder and exclude .gitignore
(
  cd "$DIST_DIR"
  # -r recursive, -q quiet
  # Exclude .gitignore explicitly as requested, plus common junk files
  zip -rq "$ZIP_PATH" "$SLUG" \
    -x "$SLUG/.gitignore" \
    -x "$SLUG/**/.gitignore" \
    -x "$SLUG/.DS_Store" \
    -x "$SLUG/**/.DS_Store"
)

echo "Created: $ZIP_PATH"
