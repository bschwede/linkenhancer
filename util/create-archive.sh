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
node_modules
dist
.git*
gulpfile.mjs
package*.json
util/wt-patch/src/*/a
util/wt-patch/patch.log
util/create-archive.sh
util/update-po-files.sh
EOT

cd "$SRCDIR" || exit 1
rsync -a --delete --exclude-from="$exclude_file" . "$DSTDIR"
rm "$exclude_file"

cd "$DSTDIR/.." || exit 1
zipfile="linkenhancer_v${VER}.zip"
rm "$zipfile"
zip -rq "$zipfile" linkenhancer
rm -rf "$DSTDIR"
echo "archive: $(realpath -m "$DSTDIR/..")/$zipfile"
