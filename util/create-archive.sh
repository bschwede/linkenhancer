#!/bin/bash
set -e
echo "-= Create release archive =-"
SCRIPTDIR=$(dirname "$(realpath -s "${BASH_SOURCE:-$0}")")
SRCDIR=$(realpath -m "$SCRIPTDIR/..")
DSTDIR=$(realpath -m "$SCRIPTDIR/../dist/linkenhancer")
VER=$(cat "$SRCDIR/latest-version.txt")

[[ ! -d "$DSTDIR" ]] && mkdir -p "$DSTDIR"

exclude_file=$(mktemp)
cat << EOT > "$exclude_file"
.vscode
node_modules
dist
.git*
gulpfile.mjs
package*.json
util/wt-patch/src/*/a
util/wt-patch/src/*/replacements.sed
util/wt-patch/patch.log
util/create-archive.sh
util/update-po-files.sh
resources/*/index-*.*
resources/img
resources/css/icons
resources/views/*.phtml.~*
resources/lang/*.po*
util/wthb-toc
EOT

cd "$SRCDIR" || exit 1
rsync -a --delete --exclude-from="$exclude_file" . "$DSTDIR"
rm "$exclude_file"

cd "$DSTDIR/.." || exit 1
zipfile="linkenhancer_v${VER}.zip"
[[ -f "$zipfile" ]] && rm "$zipfile"
zip -rq "$zipfile" linkenhancer
rm -rf "$DSTDIR"
echo "archive: $(realpath -m "$DSTDIR/..")/$zipfile"
