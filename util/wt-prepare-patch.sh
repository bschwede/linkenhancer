#!/bin/bash
SCRIPTDIRPATH=$(dirname "$(readlink -f "$0")") #SCRIPTDIR=$(dirname "$(realpath -s "${BASH_SOURCE:-$0}")")
SCRIPTFILENAME=$(basename "$0")
SCRIPTBASENAME="${SCRIPTFILENAME%.*}"
SCRIPTDATAPATH="${SCRIPTDIRPATH}/wt-patch"

PATCH_DIR="${SCRIPTDATAPATH}/patches"
SRC_DIR="${SCRIPTDATAPATH}/src"

WT_REPOBASE="https://raw.githubusercontent.com/fisharebest/webtrees/refs/"
WT_STD_REF="heads/main"
# https://raw.githubusercontent.com/fisharebest/webtrees/refs/heads/main/app/Fact.php
# https://raw.githubusercontent.com/fisharebest/webtrees/refs/tags/2.2.1/app/Fact.php



fail() {
    echo "❌ $1"
    exit 1
}

show_help() {
    echo "Usage: $0 [ACTION=get|diff] [P-DIR]"
    echo "  ACTION      what to do:"
    echo "     get      load defined files from wt-repo into a-subfolder"
    echo "     diff     diff -u a/* b/*"
    echo " "
    echo "  P-DIR       sub foldername in src dir"
    echo "              where the patch data is saved"
    exit 1
}

#--- MAIN
# parse arguments
ACTION=""
while [[ $# -gt 0 ]]; do
    case "$1" in
        get|diff)
            ACTION="$1"
            shift
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

# check
[[ -z "$ACTION" ]] || fail "No action defined"

PSRC_DIR="$SRC_DIR/$PNAME"
[[ ! -d "$PSRC_DIR" ]] || fail "Patch dir '$PSRC_DIR' doesn't exist"


if [ "get" == "$ACTION" ]; then
    PSRC_DIR_A="$PSRC_DIR/a"
    [[ ! -d "$PSRC_DIR_A" ]] || mkdir -p "$PSRC_DIR_A"
    
    repofiles="$PSRC_DIR/repofiles.txt"
    [[ ! -f "$repofiles" ]] || fail "File $repofiles not found - nothing to do"
    mapfile -t lines < "$repofiles"

    reporef="$PSRC_DIR/reporef.txt"
    WT_REF="$WT_STD_REF"
    [[  -f "$reporef" ]] && WT_REF="$(head -n 1 "$reporef")"

    sedfile="$PSRC_DIR/replacements.sed"
    WT_REPOREF="$WT_REPOBASE/$WT_REF/"
    for relfilepath in "${lines[@]}"; do     
        echo "File: $relfilepath"
        relfiledir="$(dirname "$relfilepath")"
        relfilename="$(basename "$relfilepath")"

        curl -s -o "$PSRC_DIR_A/$relfilename" "$WT_REPOREF/$relfilepath"
        #sed -E 's#^(---|\+\+\+) ([ab]/)#\1 \2app/Services/#'
        echo "s#^(---|\+\+\+) ([ab]/)$relfilename#\1 \2$relfilepath#" >> "$sedfile"
    done



    # create replacements.sed
else # diff
    #diff -u a/GedcomEditService.php b/GedcomEditService.php | sed -E 's#^(---|\+\+\+) ([ab]/)#\1 \2app/Services/#'
    
    echo
fi