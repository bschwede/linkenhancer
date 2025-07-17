#!/bin/bash

# usage: ./filter-pot.sh input.pot > output.pot

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
' "$1"
