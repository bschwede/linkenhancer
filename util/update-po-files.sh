#!/bin/bash
# I18N - Update PO-/POT-files from source code
pushd .
SCRIPTDIR=$(dirname "$(realpath -s "${BASH_SOURCE:-$0}")")


# Projektverzeichnis (Pfad zum Root deiner App)
PROJECT_ROOT=$(realpath "${SCRIPTDIR}/..")
LANG_DIR="$PROJECT_ROOT/resources/lang"
POT_FILE_ALL="$LANG_DIR/messages.all.pot"
POT_FILE_FILTERED="$LANG_DIR/messages.pot"

cd "$PROJECT_ROOT" || exit 1

# Liste unterstützter Sprachen (ISO-Codes)
LANGUAGES=("de" "en")

echo "📦 Generiere POT-Datei: $POT_FILE_ALL"

# Erzeuge messages.pot mit relativen Pfaden
xgettext -L PHP \
  --keyword=translate \
  --add-comments=I18N \
  --from-code=utf-8 \
  --output="$POT_FILE_ALL" \
  $(find . -name "*.php" -o -name "*.phtml")

echo "✅ POT-Datei erstellt."


awk '
BEGIN {
  in_block = 0;
  skip_block = 0;
  block = "";
}
# Leere Zeile markiert Ende eines Blocks
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
  # Beginn eines neuen Blocks
  if (!in_block) {
    in_block = 1;
    block = "";
  }

  # Prüfen, ob Kommentar enthalten ist
  if ($0 ~ /^#. I18N: webtrees.pot/) {
    skip_block = 1;
  }

  # Zeile zum Block hinzufügen
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

# Initialisiere PO-Dateien
for lang in "${LANGUAGES[@]}"; do
  PO_FILE="$LANG_DIR/$lang.po"

  if [[ -f "$PO_FILE" ]]; then
    echo "🔄 Aktualisiere bestehende PO-Datei für [$lang]"
    msgmerge --update --backup=none "$PO_FILE" "$POT_FILE"
  else
    echo "🆕 Erzeuge neue PO-Datei für [$lang]"
    msginit --input="$POT_FILE" --locale="$lang" --output-file="$PO_FILE" --no-translator
  fi
done

echo "✅ Alle Sprachdateien sind aktuell."
popd || exit 1
exit 0