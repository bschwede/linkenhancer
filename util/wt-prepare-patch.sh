#!/bin/bash
# Helper for creating small Webtrees patches that fixes tiny code chunks
# an issue should be filed on github for the underlying problem.
# In the meantime until implementation in the official sources those patches could
# be automatically applied
SCRIPTDIRPATH=$(dirname "$(realpath -s "$0")") #SCRIPTDIR=$(dirname "$(realpath -s "${BASH_SOURCE:-$0}")")
SCRIPTFILENAME=$(basename "$0")
SCRIPTBASENAME="${SCRIPTFILENAME%.*}"
SCRIPTDATAPATH="${SCRIPTDIRPATH}/wt-patch"

PATCH_DIR="${SCRIPTDATAPATH}/patches"
SRC_DIR="${SCRIPTDATAPATH}/src"

WT_REPOBASE="https://raw.githubusercontent.com/fisharebest/webtrees/refs/"
WT_STD_REF="heads/main"
WTDIR="$(realpath -L "$SCRIPTDIRPATH/../../..")"
# https://raw.githubusercontent.com/fisharebest/webtrees/refs/heads/main/app/Fact.php
# https://raw.githubusercontent.com/fisharebest/webtrees/refs/tags/2.2.1/app/Fact.php



fail() {
    echo "❌ $1"
    exit 1
}

show_help() {
    echo "Helper for creating small Webtrees patches that fixes tiny code chunks"
    echo "Usage: $0 [ACTION] [P-DIR]"
    echo "  ACTION      what to do:"
    echo "     new      creates sub dir with next free P-number"
    echo "     get      load defined files from wt-repo into a-subfolder"
    echo "     diff     diff -u a/* b/*"
    echo " "
    echo "  P-DIR       sub foldername in src dir"
    echo "              where the patch data is saved"
    exit 1
}

get_next_pnum() {
    lastp=$(find "$SRC_DIR" -maxdepth 1 -name "P*" -type d | sort -r | head -n 1 | grep -o -P "\d+$")
    nextpname="P$(printf %03d $((lastp+1)))"
    mkdir -p "$SRC_DIR/$nextpname"
    echo "$nextpname"
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

#--- MAIN
# parse arguments
ACTION=""
PNAME=""
while [[ $# -gt 0 ]]; do
    case "$1" in
        get|diff)
            ACTION="$1"
            shift
            ;;
        new)
            ACTION="$1"
            PNAME=$(get_next_pnum)
            break
            ;;
        -h|--help)
            show_help
            ;;
        *)
            PNAME="$1"
            shift
            ;;
    esac
done

# check and init vars
[[ -z "$PNAME" ]] && fail "No patch sub directory name P000 given"
[[ -z "$ACTION" ]] && fail "No action defined"
echo "-= $PNAME - $ACTION =-"


PSRC_DIR="$SRC_DIR/$PNAME"
[[ ! -d "$PSRC_DIR" ]] && fail "Patch dir '$PSRC_DIR' doesn't exist"

PSRC_DIR_A="$PSRC_DIR/a"
[[ ! -d "$PSRC_DIR_A" ]] && mkdir -p "$PSRC_DIR_A"

PSRC_DIR_B="$PSRC_DIR/b"

# sed filter file for unflattening filepaths in patch file
sedfile="$PSRC_DIR/replacements.sed"

reporef="$PSRC_DIR/reporef.txt"
repofiles="$PSRC_DIR/repofiles.txt"

case "$ACTION" in
    new)
        [[ ! -d "$PSRC_DIR_B" ]] && mkdir -p "$PSRC_DIR_B"
        
        WT_REF="$WT_STD_REF"
        WTFILE="$WTDIR/app/Webtrees.php"
        if [[ -f "$WTFILE" ]]; then
            WTVERSION="$(get_wt_version "$WTFILE")"
            echo "WT base path: $WTDIR"
            echo "WT version  : $WTVERSION"
            WT_REF="tags/$WTVERSION"
        fi
        echo -n "$WT_REF" > "$reporef"

        touch "$repofiles"
        vi "$repofiles"

        read -n 1 -p "Would you like execute GET? (N/y) " answer;
        case $answer in
            y|Y|j|J)
                "$0" get $PNAME
                ;;
        esac
        ;;
    get) 
        # ref: heads/main, tags/2.2.1 ...
        WT_REF="$WT_STD_REF"
        [[  -f "$reporef" ]] && WT_REF="$(head -n 1 "$reporef")"
        [[ -z "$WT_REF" ]] && fail "Repo ref is not defined"

        # relative filepaths https://raw.githubusercontent.com/fisharebest/webtrees/refs/[ref]/ >> app/Fact.php <<
        [[ ! -f "$repofiles" ]] && fail "File $repofiles not found - nothing to do"
        mapfile -t lines < "$repofiles"

        [[ -f "$sedfile" ]] && rm "$sedfile"

        WT_REPOREF="${WT_REPOBASE}${WT_REF}/"
        echo "repo base: $WT_REPOREF"
        for relfilepath in "${lines[@]}"; do     
            # skip blank lines
            relfilepath=$( echo -n "$relfilepath" | sed 's/^[[:blank:]]*//;s/[[:blank:]]*$//')
            [[ -z "$relfilepath" ]] && continue
            
            echo "- $relfilepath"
            relfilename="$(basename "$relfilepath")"
            curl -s -o "$PSRC_DIR_A/$relfilename" "${WT_REPOREF}$relfilepath"
            echo "s#^(---|\+\+\+) ([ab]/)$relfilename#\1 \2$relfilepath#" >> "$sedfile"
        done
        ;;
    
    diff)
        [[ ! -d "$PSRC_DIR_A" ]] && fail "a-subdir for $PNAME doesn't exist - nothing to do"
        [[ ! -d "$PSRC_DIR_B" ]] && fail "b-subdir for $PNAME doesn't exist - nothing to do"

        cd "$PSRC_DIR"
        patchfile="$PATCH_DIR/$PNAME.patch"
        
        # -B   ignore changes where lines are all blank
        # -r   recursively compare any subdirectories found
        # -w   ignore all white space
        diff -urwB a b | sed -E -f "$sedfile" | tee "$patchfile" | less
        ;;

    *)
        fail "'$ACTION' not implemented"
        ;;
esac
