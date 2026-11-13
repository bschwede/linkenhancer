#!/bin/sh
# Local equivalent of the Forgejo sync-docs action.
# Generates all doc artifacts (EN + DE) and writes them to a target directory.
# No git operations — manual commit is the user's responsibility.
#
# Usage:
#   sh util/sync-docs-local.sh [--target=<dir>]
#
# Default target: ../wt-modules
# Output: diff -u report for each artifact (if the target file already exists).

set -eu

# --- Parse args ---
TARGET="../wt-modules"
for arg in "$@"; do
    case "$arg" in
        --target=*) TARGET="${arg#--target=}" ;;
        *) echo "Unknown option: $arg" >&2; exit 1 ;;
    esac
done

# --- Resolve paths relative to repo root ---
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(dirname "$SCRIPT_DIR")"
cd "$REPO_ROOT"

# --- Hint if target doesn't look like a Hugo site ---
if [ ! -f "$TARGET/hugo.yaml" ] && [ ! -f "$TARGET/hugo.toml" ]; then
    echo "NOTE: $TARGET does not look like a Hugo site (no hugo.yaml found)." >&2
    echo "      Artifacts will still be written." >&2
    echo "" >&2
fi

# --- Prepare target directories (both languages) ---
mkdir -p "$TARGET/static/data"
mkdir -p "$TARGET/content/en/includes/linkenhancer"
mkdir -p "$TARGET/content/de/includes/linkenhancer"

changed=0
total=0

sync_artifact() {
    local src="$1"
    local dst="$2"
    total=$((total + 1))

    if [ -f "$dst" ]; then
        if diff -u "$dst" "$src" > /dev/null 2>&1; then
            echo "unchanged: $(basename "$dst")"
        else
            diff -u "$dst" "$src" | sed "s|$dst|a/$(basename "$dst")|g;s|$src|b/$(basename "$dst")|g" >&2
            cp "$src" "$dst"
            echo "changed:   $(basename "$dst")"
            changed=$((changed + 1))
        fi
    else
        cp "$src" "$dst"
        echo "new:       $(basename "$dst")"
        changed=$((changed + 1))
    fi
}

TMPDIR_SYNC="$(mktemp -d)"
trap 'rm -rf "$TMPDIR_SYNC"' EXIT

# --- 1. help-mapping CSV ---
cp src/Schema/SeedHelpTable.csv "$TMPDIR_SYNC/help-mapping.csv"
sync_artifact "$TMPDIR_SYNC/help-mapping.csv" "$TARGET/static/data/help-mapping.csv"

# --- 2. help-mapping.version.json (only update if hash changed) ---
HASH=$(sha256sum src/Schema/SeedHelpTable.csv | cut -c1-12)
VERSION_DST="$TARGET/static/data/help-mapping.version.json"
if [ -f "$VERSION_DST" ] && grep -q "\"hash\": \"$HASH\"" "$VERSION_DST" 2>/dev/null; then
    total=$((total + 1))
    echo "unchanged: help-mapping.version.json"
else
    printf '{"hash": "%s", "updated": "%s"}\n' "$HASH" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" > "$TMPDIR_SYNC/help-mapping.version.json"
    sync_artifact "$TMPDIR_SYNC/help-mapping.version.json" "$VERSION_DST"
fi

# --- 3. DE i18n extraction (raw PO → JSON, prerequisite for DE fragments) ---
node util/extract-po-json.mjs > util/i18n-de.json

# --- 4. class-hierarchy fragment (language-neutral, identical EN+DE) ---
php util/generate-class-hierarchy.php > "$TMPDIR_SYNC/class-hierarchy.md"
sync_artifact "$TMPDIR_SYNC/class-hierarchy.md" "$TARGET/content/en/includes/linkenhancer/class-hierarchy.md"
sync_artifact "$TMPDIR_SYNC/class-hierarchy.md" "$TARGET/content/de/includes/linkenhancer/class-hierarchy.md"

# --- 5. cron-jobs fragment (EN + DE) ---
php util/generate-cron-jobs-doc.php > "$TMPDIR_SYNC/cron-jobs-en.md"
sync_artifact "$TMPDIR_SYNC/cron-jobs-en.md" "$TARGET/content/en/includes/linkenhancer/cron-jobs.md"

php util/generate-cron-jobs-doc.php --lang=de > "$TMPDIR_SYNC/cron-jobs-de.md"
sync_artifact "$TMPDIR_SYNC/cron-jobs-de.md" "$TARGET/content/de/includes/linkenhancer/cron-jobs.md"

# --- 6. enhanced-links parameter reference (EN + DE) ---
php util/export-le-i18n.php > util/le-i18n-en.json
php util/export-le-i18n.php --lang=de > util/le-i18n-de.json
node util/generate-link-params.mjs > "$TMPDIR_SYNC/le-link-parameters-en.md"
sync_artifact "$TMPDIR_SYNC/le-link-parameters-en.md" "$TARGET/content/en/includes/linkenhancer/le-link-parameters.md"

node util/generate-link-params.mjs --lang=de > "$TMPDIR_SYNC/le-link-parameters-de.md"
sync_artifact "$TMPDIR_SYNC/le-link-parameters-de.md" "$TARGET/content/de/includes/linkenhancer/le-link-parameters.md"

# --- Summary ---
echo ""
echo "Done: $changed/$total artifacts changed → $TARGET"
