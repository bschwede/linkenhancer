/*
  convert PO translation files to php array files for using with webtrees

See forum thread: convert mo/po - file to php-file (https://www.webtrees.net/index.php/forum/webtrees-help-and-support/40583-convert-mo-po-file-to-php-file?start=10)

    | why would Greg convert his mo/po-files into php, if there is no advantage doing so?
    Performance. PHP compiles files into "opcode", and then caches them.
    ...
    I only use .PO files so that translators can use external tools (e.g. POEdit, weblate, etc.) to make the translations.

For the implementation see also:
- https://github.com/fisharebest/webtrees/blob/main/app/I18N.php
- https://github.com/fisharebest/localization/blob/main/src/Translation.php
    
    +++ PHP-snippet
    const PLURAL_SEPARATOR       = "\x00";
    const CONTEXT_SEPARATOR      = "\x04";
    ...
    if ($msgctxt !== '') {
        $msgid = $msgctxt . self::CONTEXT_SEPARATOR . $msgid;
    }

    if ($msgid_plural !== '') {
        $msgid .= self::PLURAL_SEPARATOR . $msgid_plural;
        ksort($plurals);
        $msgstr = implode(self::PLURAL_SEPARATOR, $plurals);
    }
    --- PHP-snippet
*/
import * as fs from "node:fs/promises"
import path from "node:path"
import gettextParser from "gettext-parser" // https://www.npmjs.com/package/gettext-parser
import jsPhpData from "js-php-data"
import log from 'fancy-log'


const PLURAL_SEP  = "\x00";
const CONTEXT_SEP = "\x04";

async function findPoFiles(srcPath) {
    const poFiles = [];

    async function walk(dir) {
        const entries = await fs.readdir(dir, { withFileTypes: true });
        for (const entry of entries) {
            const fullPath = path.join(dir, entry.name);
            if (entry.isDirectory()) {
                await walk(fullPath);
            } else if (entry.isFile() && entry.name.endsWith(".po")) {
                poFiles.push(fullPath);
            }
        }
    }

    await walk(srcPath);
    return poFiles;
}


export async function convertPo2php(srcPath) {
    const poFiles = await findPoFiles(srcPath);

    for (const file of poFiles) {
        try {
            const content = await fs.readFile(file);
            const po = gettextParser.po.parse(content);

            const flatArray = {};
            for (const context in po.translations) {
                for (const msgid in po.translations[context]) {
                    if (msgid !== '') { // po file metadata is stored in msgid '' - not necessary
                        const msg = po.translations[context][msgid];
                        let key = (context !== '' ? context + CONTEXT_SEP : '') 
                            + msgid 
                            + ((msg.msgid_plural ?? false) ? PLURAL_SEP + msg.msgid_plural : '')
                        if (msg.msgstr?.length > 0 && msg.msgstr[0]) {
                            flatArray[key] = msg.msgstr.join(PLURAL_SEP);
                        }
                    }
                }
            }

            const phpArrayExpr = jsPhpData(flatArray, {
                bracketArrays: true,
                indentation: 0,
            });

            const phpFile = file.replace(/\.po$/, ".php");
            const phpSource = `<?php\n\nreturn ${phpArrayExpr};\n`;

            await fs.writeFile(phpFile, phpSource);

            log(`✅ ${file} → ${phpFile}`);
        } catch (err) {
            log.error(`❌ Error processing ${file}:`, err.message);
        }
    }
}