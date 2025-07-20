#!/bin/bash
#set -e
SCRIPTDIRPATH=$(dirname "$(readlink -f "$0")") #SCRIPTDIR=$(dirname "$(realpath -s "${BASH_SOURCE:-$0}")")
SCRIPTFILENAME=$(basename "$0")
SCRIPTBASENAME="${SCRIPTFILENAME%.*}"
SCRIPTDATAPATH="${SCRIPTDIRPATH}/${SCRIPTBASENAME}"

PATCH_DIR="${SCRIPTDATAPATH}/patches"
BAK_DIR="${SCRIPTDATAPATH}/bak"
LOG_FILE="${SCRIPTDATAPATH}/patch.log"
HASH_FILE="${SCRIPTDATAPATH}/patch_hashes.txt"
WTDIR="$(realpath -L "$SCRIPTDIRPATH/../../..")"


log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOG_FILE"
}

# Calculate SHA256 hash of a file
file_hash() {
    sha256sum "$1" | cut -d ' ' -f1
}

# Save/check hashes already applied
has_been_applied() { # not in use
    local hash="$1"

    [[ -z "$hash" || ! -s "$HASH_FILE" ]] && return 1

    #grep -Fxq "$hash" "$HASH_FILE" #exakt ganze Zeile - passt hier nicht mehr
    grep -q "^$1$" "$HASH_FILE"
}

mark_as_applied() {
    echo "$*" >> "$HASH_FILE"
}

get_wt_version() {
    php_file="$1"

    # Read out stability
    stability=$(grep -E "public const .*STABILITY\s*=" "$php_file" \
        | sed -E "s/.*STABILITY\s*=\s*'([^']*)'.*/\1/")

    # Read out version part without stability
    version_raw=$(grep -E "public const string VERSION\s*=" "$php_file" \
        | sed -E "s/.*VERSION\s*=\s*'([^']+)'.*/\1/")

    full_version="${version_raw}${stability}"
    
    echo "$full_version"
}

show_help() {
    echo "Usage: $0 [-R] [PATCHNAME_BASIS]"
    echo "  -R                  undo Patch (patch -R)"
    echo "  PATCHNAME_BASIS     optional name filter (e.g. '01')"
    exit 1
}


#--- MAIN
REVERSE=""
WTFILE="$WTDIR/app/Webtrees.php"
[[ -f "$WTFILE" ]] || { log "❌ no webtrees found in $WTDIR"; exit 1; }
WTVERSION="$(get_wt_version "$WTFILE")"
echo "Webtrees Version: $WTVERSION"
mkdir -p "$BAK_DIR"


# parse arguments
while [[ $# -gt 0 ]]; do
    case "$1" in
        -R)
            REVERSE="-R "
            shift
            ;;
        -h|--help)
            show_help
            ;;
        *)
            FILTER="$1"
            shift
            ;;
    esac
done

cd "$WTDIR"
for patch in "$(realpath -s --relative-to="$WTDIR" "$PATCH_DIR")"/*.patch; do
    [[ -e "$patch" ]] || continue

    base_name=$(basename "$patch" .patch)

    # If a filter is specified: skip everything that does not fit
    if [[ -n "$FILTER" && "$base_name" != *"$FILTER"* ]]; then
        continue
    fi

    # Extract target file name (z.B. foo_1.patch → foo)
    target_file=$(echo "$base_name" | sed 's/_P[0-9]\+$//')
   
    # Collect result as an array
    mapfile -t matches < <(find "${WTDIR}"/{app,resources} -name "${target_file}")

    # Anzahl prüfen
    if [[ ${#matches[@]} -eq 0 ]]; then
        log "❌ No file found for '${target_file}'"
        continue
    elif [[ ${#matches[@]} -gt 1 ]]; then
        log "❌ Several files found for '${target_file}':"
        printf '  - %s\n' "${matches[@]}" | tee -a "$LOG_FILE"
        continue
    fi

    # Create exactly one file → relative path specification
    target_path="$(realpath -s --relative-to="$WTDIR" "${matches[0]}")"


    if [[ ! -f "$target_path" ]]; then
        log "⚠️ Target file ‘$target_path’ not found. Skip patch '${patch##*/}'."
        continue
    fi

    current_hash=$(file_hash "$target_path")

#    if has_been_applied "$current_hash"; then
#        log "Patch '${patch##*/}' has already been applied to ‘$target_path’ (hash recognized)."
#        continue
#    fi

    log "▶️  Apply patch $REVERSE'${patch##*/}' to '$target_path' …"

    # create backup
    bak_basename="${target_file}.bak.$(date +%s)"
    cp "$target_path" "$BAK_DIR/$bak_basename"

    # apply patch
    patch_output=$(mktemp)
    if patch -N $REVERSE -r - -p0 "$target_path" < "$patch" >"$patch_output" 2>&1; then
        cat "$patch_output" | tee -a "$LOG_FILE"
        log "✅ Patch '${patch##*/}' successfully applied."
        new_hash=$(file_hash "$target_path")
        mark_as_applied "$new_hash" "$target_file" "$current_hash" "$bak_basename"
    else
        cat "$patch_output" | tee -a "$LOG_FILE"
        log "❌ Patch '${patch##*/}' could not be applied!"
    fi
    rm "$patch_output"
done
