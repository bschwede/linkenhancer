#!/bin/bash
# Helper script for updating table of contents for webtrees manual from GenWiki-MediaWiki page
# required: jq
SCRIPTDIRPATH=$(dirname "$(realpath -s "$0")")
DATADIR="${SCRIPTDIRPATH}/wthb-toc"

GWAPIURL="https://wiki.genealogy.net/api.php"
WIKIPAGE="Vorlage:WT-Verzeichnis-TOC"


[[ ! -d "$DATADIR" ]] && mkdir -p "$DATADIR"

echo "Query parse result of $WIKIPAGE via API from GenWiki..."
APIJSONFILE="$DATADIR/gw-api-parse-toc-template.json"
APIHTMLFILE="$DATADIR/gw-api-parse-toc-template.html"
VIEWFILE="$SCRIPTDIRPATH/../resources/views/help-wthb-toc.phtml"
WIKITEXT_ENC="$(echo -n "{{$WIKIPAGE}}" | jq -sRr @uri)"

response=$(curl -f --silent --show-error -w "%{http_code}" -o "$APIJSONFILE" "${GWAPIURL}?action=parse&format=json&text=${WIKITEXT_ENC}&disablelimitreport=1&disableeditsection=1&disabletoc=1")
status=$?
http_code=$(tail -n1 <<< "$response")

if [ $status -ne 0 ] || [ "$http_code" -ge 400 ]; then
  echo "!curl error: Exit-Code $status, HTTP-Code $http_code"
  exit 1
fi

# Extracting the HTML content from JSON (jq required)
jq -r '.parse.text["*"]' "$APIJSONFILE" > "$APIHTMLFILE"

[[ ! -f "$VIEWFILE" ]] && echo "!View file not found: $VIEWFILE" && exit 1

# compare view and downloaded file
hash1=$(sha256sum "$APIHTMLFILE" | cut -d ' ' -f1)
hash2=$(sha256sum "$VIEWFILE" | cut -d ' ' -f1)

if [ "$hash1" = "$hash2" ]; then
  echo "The view file and the downloaded file are identical - no action is required (SHA-256 Hash: $hash1)."
  exit 0
fi

# test content
echo "Test content of $APIHTMLFILE..."
grep -q "<div class=\"wthbtoc\">" "$APIHTMLFILE" || { echo "!div-tag with class wthbtoc not found"; exit 2; }
HTMLSIZE="$(wc -c "$APIHTMLFILE" | cut -d ' ' -f1)"
[ "$HTMLSIZE" -lt 150000 ] && { echo "!File size seems too be to small"; exit 2; }
[ "$HTMLSIZE" -gt 600000 ] && { echo "!File size seems too be to big"; exit 2; }
grep -q "<script" "$APIHTMLFILE" && { echo "!text contains script-tags - please check"; exit 2; }
echo "OK"

echo "Overwrite view file (with numbered backup)"
cp --backup=numbered -f "$APIHTMLFILE" "$VIEWFILE"
