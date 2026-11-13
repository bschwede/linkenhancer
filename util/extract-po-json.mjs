#!/usr/bin/env node
// Extracts translations from a PO file into a flat JSON map { msgid: msgstr }.
// Used as a pre-step for generating DE doc fragments (cron-jobs, link-params).
//
// Uses the same gettext-parser pattern as convert-po2php.js.
//
// Usage: node util/extract-po-json.mjs [po-file]
// Default po-file: resources/lang/de.po
// Output: stdout (JSON)

import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import gettextParser from 'gettext-parser';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const poFile = process.argv[2] || path.join(__dirname, '../resources/lang/de.po');

const content = readFileSync(poFile);
const po = gettextParser.po.parse(content);

const map = {};
for (const context in po.translations) {
    for (const msgid in po.translations[context]) {
        if (msgid === '') continue; // skip metadata
        const msg = po.translations[context][msgid];
        const key = (context !== '' ? context + '\x04' : '') + msgid
            + ((msg.msgid_plural ?? false) ? '\x00' + msg.msgid_plural : '');
        if (msg.msgstr?.length > 0 && msg.msgstr[0]) {
            map[key] = msg.msgstr.join('\x00');
        }
    }
}

console.log(JSON.stringify(map, null, 2));
