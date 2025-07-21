#!/bin/bash
#set -e
#echo "readlink -f: $(dirname "$(readlink -f "$0")")"
#echo "realpath -s: $(dirname "$(realpath -s "${BASH_SOURCE:-$0}")")"
#exit 0
#SCRIPTDIRPATH=$(dirname "$(readlink -f "$0")") #SCRIPTDIR=$(dirname "$(realpath -s "${BASH_SOURCE:-$0}")")
SCRIPTDIRPATH=$(dirname "$(realpath -s "${BASH_SOURCE:-$0}")")
SCRIPTFILENAME=$(basename "$0")
SCRIPTBASENAME="${SCRIPTFILENAME%.*}"
SCRIPTDATAPATH="${SCRIPTDIRPATH}/${SCRIPTBASENAME}"

PATCH_DIR="${SCRIPTDATAPATH}/patches"
LOG_FILE="${SCRIPTDATAPATH}/patch.log"
WTDIR="$(realpath -L "$SCRIPTDIRPATH/../../..")"


log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOG_FILE"
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
    echo "Usage: $0 [-R] [FILTER]"
    echo "  -R                  undo Patch (patch -R)"
    echo "  FILTER              '*' for all or specific (e.g. '01')"
    exit 1
}


#--- MAIN
REVERSE=""
WTFILE="$WTDIR/app/Webtrees.php"
[[ -f "$WTFILE" ]] || { log "❌ no webtrees found in $WTDIR"; exit 1; }
WTVERSION="$(get_wt_version "$WTFILE")"
echo "WT base path: $WTDIR"
echo "WT version  : $WTVERSION"


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
[[ -z "$FILTER" ]] && { echo "No FILTER defined"; show_help; exit 1; }
[[ "$FILTER" == "*" ]] && FILTER=""

cd "$WTDIR"
for patch in "$(realpath -s --relative-to="$WTDIR" "$PATCH_DIR")"/*.patch; do
    [[ -e "$patch" ]] || continue

    base_name=$(basename "$patch" .patch)

    # If a filter is specified: skip everything that does not fit
    if [[ -n "$FILTER" && "$base_name" != *"$FILTER"* ]]; then
        continue
    fi

    log "▶️  Apply patch $REVERSE'${patch##*/}' …"

    # apply patch
    patch_output=$(mktemp)
    if patch -N $REVERSE -r - -p1 < "$patch" >"$patch_output" 2>&1; then
        cat "$patch_output" | tee -a "$LOG_FILE"
        log "✅ Patch '${patch##*/}' successfully applied."
    else
        cat "$patch_output" | tee -a "$LOG_FILE"
        log "❌ Patch '${patch##*/}' could not be applied!"
    fi
    rm "$patch_output"
done
