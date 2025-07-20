#!/bin/bash
# I18N - Update PO-/POT-files from source code
#pushd .
SCRIPTDIR=$(dirname "$(realpath -s "${BASH_SOURCE:-$0}")")

PROJECT_ROOT=$(realpath "${SCRIPTDIR}/..")
LANG_DIR="$PROJECT_ROOT/resources/lang"
POT_FILE_ALL="$LANG_DIR/messages.all.pot"
POT_FILE_FILTERED="$LANG_DIR/messages.pot"

cd "$PROJECT_ROOT" || exit 1

# list of supported lLanguages (ISO-Codes)
LANGUAGES=("de" "en")

echo "📦 Generate POT-File: $POT_FILE_ALL"

# Erzeuge messages.pot mit relativen Pfaden
xgettext -L PHP \
  --keyword=translate \
  --add-comments=I18N \
  --from-code=utf-8 \
  --output="$POT_FILE_ALL" \
  $(find . -not -path "./util/*" \( -name "*.php" -o -name "*.phtml" \))

echo "✅ POT-file created."


awk '
BEGIN {
  in_block = 0;
  skip_block = 0;
  block = "";
}
# blank line marks end of block
/^$/ {
  if (!skip_block) {
    printf "%s\n", block;
  }
  block = "";
  in_block = 0;
  skip_block = 0;
  next;
}
{
  # begin of new block
  if (!in_block) {
    in_block = 1;
    block = "";
  }

  # check if specific filter comment is present - we do not need to translate standard webtrees entries again
  if ($0 ~ /^#. I18N: webtrees.pot/) {
    skip_block = 1;
  }

  # add line to block
  block = block $0 "\n";
}
END {
  # Letzter Block ohne abschließende Leerzeile behandeln
  if (in_block && !skip_block) {
    printf "%s\n", block;
  }
}
' "$POT_FILE_ALL" > "$POT_FILE_FILTERED"
POT_FILE="$POT_FILE_FILTERED"

# init PO-files
for lang in "${LANGUAGES[@]}"; do
  PO_FILE="$LANG_DIR/$lang.po"

  if [[ -f "$PO_FILE" ]]; then
    echo "🔄 Update existing PO-file for [$lang]"
    msgmerge --update --backup=none "$PO_FILE" "$POT_FILE"
  else
    echo "🆕 Create new PO-file for [$lang]"
    msginit --input="$POT_FILE" --locale="$lang" --output-file="$PO_FILE" --no-translator
  fi
done

echo "✅ All language files are up to date."
#popd || exit 1
exit 0