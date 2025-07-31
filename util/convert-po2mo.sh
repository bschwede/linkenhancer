#!/bin/bash
echo "-= I18N - convert PO to MO =-"
SCRIPTDIR=$(dirname "$(realpath -s "${BASH_SOURCE:-$0}")")
LANGDIR=$(realpath "$SCRIPTDIR/../resources/lang")

for pofile in "$LANGDIR"/*.po; do
    mofile="${pofile%.*}.mo"
    echo "- ${mofile}"
    msgfmt -o "$mofile" "$pofile"
done