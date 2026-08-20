#!/bin/bash
#
# Build a distributable ZIP of the plugin (builds/fluent-cart-migrator.zip).
#
# Mirrors fluent-cart's resources/dev/build.sh: only the whitelisted files and
# folders below are packed, staged under the plugin slug so the archive root is
# always fluent-cart-migrator/ regardless of the checkout directory name.
#
# Usage:  npm run zip          (pack what is currently built)
#         npm run build:zip    (vite build + pack)

set -o pipefail

RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m'

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SOURCE_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
PLUGIN_SLUG="fluent-cart-migrator"
BUILDS_DIR="$SOURCE_DIR/builds"
OUTPUT_FILE="$BUILDS_DIR/${PLUGIN_SLUG}.zip"

# Single source of truth for what ships. Everything else (docs, sources in
# assets/js, vite/npm config, lock files, markdown, root .pot copies) stays out.
BUILD_WHITELIST=(
    "fluent-cart-migrator.php"
    "index.php"
    "readme.txt"
    "Classes"
    "views"
    "languages"
    "assets/build"
    "assets/images"
)

# Patterns excluded from inside the whitelisted folders.
EXCLUDE_ARGS=(
    "-x" "*.DS_Store"
    "-x" "*/.DS_Store"
    "-x" "*.git*"
    "-x" "*.map"
    "-x" "*.pot~"
)

if ! command -v zip >/dev/null 2>&1; then
    echo -e "${RED}❌ 'zip' binary not found in PATH${NC}"
    exit 1
fi

if [[ ! -f "$SOURCE_DIR/assets/build/migrator-app.js" ]]; then
    echo -e "${RED}❌ assets/build/migrator-app.js is missing — run 'npm run build' first (or use 'npm run build:zip')${NC}"
    exit 1
fi

mkdir -p "$BUILDS_DIR"
[[ -f "$OUTPUT_FILE" ]] && rm -f "$OUTPUT_FILE"

echo -e "${BLUE}📦 Creating ZIP archive for ${PLUGIN_SLUG}...${NC}"

# Stage symlinks under the slug; zip dereferences them (-r without -y).
STAGE_DIR="$(mktemp -d)"
trap 'rm -rf "$STAGE_DIR"' EXIT
mkdir -p "$STAGE_DIR/$PLUGIN_SLUG"

INCLUDE_PATHS=()
for item in "${BUILD_WHITELIST[@]}"; do
    if [[ ! -e "$SOURCE_DIR/$item" ]]; then
        echo -e "${YELLOW}⚠️  Skipping missing whitelist entry: ${item}${NC}"
        continue
    fi
    mkdir -p "$STAGE_DIR/$PLUGIN_SLUG/$(dirname "$item")"
    ln -s "$SOURCE_DIR/$item" "$STAGE_DIR/$PLUGIN_SLUG/$item"
    INCLUDE_PATHS+=("${PLUGIN_SLUG}/${item}")
done

if [[ ${#INCLUDE_PATHS[@]} -eq 0 ]]; then
    echo -e "${RED}❌ Nothing to zip — no whitelist entry exists!${NC}"
    exit 1
fi

cd "$STAGE_DIR" || exit 1

TOTAL_FILES=$(find -L "${INCLUDE_PATHS[@]}" \( -name '.DS_Store' -o -name '*.git*' -o -name '*.map' -o -name '*.pot~' \) -prune -o -type f -print 2>/dev/null | wc -l | tr -d ' ')
echo -e "${BLUE}📊 Packing ${TOTAL_FILES} files${NC}"

zip -r9 -q "$OUTPUT_FILE" "${INCLUDE_PATHS[@]}" "${EXCLUDE_ARGS[@]}"
ZIP_STATUS=$?

cd "$SOURCE_DIR" || exit 1

if [[ "$ZIP_STATUS" -ne 0 ]]; then
    echo -e "${RED}❌ zip failed with exit code ${ZIP_STATUS} — discarding partial archive${NC}"
    rm -f "$OUTPUT_FILE"
    exit 1
fi

if [[ "$OSTYPE" == "darwin"* ]]; then
    FILE_SIZE=$(stat -f%z "$OUTPUT_FILE")
else
    FILE_SIZE=$(stat -c%s "$OUTPUT_FILE")
fi
FILE_SIZE_KB=$(( FILE_SIZE / 1024 ))

echo -e "${BLUE}📋 Included:${NC}"
for item in "${BUILD_WHITELIST[@]}"; do
    echo -e "   ${item}"
done
echo -e "${GREEN}✅ ZIP file created: ${OUTPUT_FILE}${NC}"
echo -e "${GREEN}📏 Plugin size: ${FILE_SIZE_KB} KB${NC}"
