/**
 * gulp/build tasks - bundle and manage javascript files
 */

import * as fs from 'node:fs/promises';
import path from "path";

import log from 'fancy-log';

import gulp from "gulp";
import size from "gulp-size";
import terser from "gulp-terser";
import source from "vinyl-source-stream";
import buffer from "vinyl-buffer";

// sourcemaps don't work as expected - because of media firewall for assets
// map-file must be inline or maybe in public folder, but tests were not successful 
//import sourcemaps from 'gulp-sourcemaps'; 

import rollupStream from "@rollup/stream";
import { babel as rollupBabel } from "@rollup/plugin-babel";
import { nodeResolve } from "@rollup/plugin-node-resolve";
import commonjs from "@rollup/plugin-commonjs";

import { globSync } from 'glob';
import merge from 'merge-stream';

import { fileExists } from './utils.js'

const JSMODNAME = "LinkEnhMod"

let DEV = false; // bundling js for dev or production

const rollupConfig = (inputFile, sourcemaps = false, format = 'iife') => {
    return {
        input: inputFile,
        output: {
            format: format,
            name: JSMODNAME,
            sourcemap: sourcemaps, //not in use
        },
        //treeshake: false,
        plugins: [
            //eslint({ throwOnError: true }),
            rollupBabel({
                babelHelpers: "bundled",
                extensions: [".js"],
                exclude: "node_modules/**"
            }),
            nodeResolve({
                extensions: [".js", ".ts"],
                moduleDirectories: ['node_modules'],
            }),
            commonjs(),
        ],
    };
};

const getTerserCfg = () => DEV ? { compress: false, mangle: false, format: { beautify: true } } : {};

const jsPipe = (inputFile, outputInfix, format = 'iife') =>
    rollupStream(rollupConfig(inputFile, false, format))
        .pipe(source(`bundle-${outputInfix}.min.js`))
        .pipe(buffer())
        //.pipe(sourcemaps.init())
        .pipe(terser(getTerserCfg()))
        //.pipe(sourcemaps.write('.'))               // separate .map files - doesn't work in webtrees because of assets firewall
        .pipe(gulp.dest("./resources/js"))
        .pipe(size({ showFiles: true }));

export function toggleDevMode(status) {
    DEV = status
}

export const jsExtractLeConfig = async () => {
    const srcfile = "./resources/js/src/le-config.js";
    const dstfile = "./resources/js/bundle-le-config.js";
    if (! await fileExists(srcfile)) {
        log.error(`${srcfile} not exists`);
        return;
    }
    let jscode = await fs.readFile(srcfile, 'utf8');

    const snippetRegex = /\/\/ *\+{3,} *code-snippet.*?\r?\n(.+?)\/\/ *-{3,} *code-snippet/is;

    const match = jscode.match(snippetRegex);
    if (match) {
        await fs.writeFile(dstfile, match[1], 'utf8');
        log(`${dstfile} was created`);
    } else {
        log.error(`no code snippet found`);
    }
}

export const jsIndex = () => {
    const files = globSync('./resources/js/index-*.js');

    if (files.length === 0) {
        log('jsIndex: keine Dateien gefunden – übersprungen');
        // Leeren, sofort endenden Stream zurückgeben:
        return merge(); // merge() ohne Argumente endet sofort
    }

    const streams = files.map((filename) => {
        const infix = path.basename(filename, '.js').replace(/^index-/, '');
        const s = jsPipe(filename, infix);
        s.on('error', (err) => {
            log.error(`jsIndex: Fehler in Pipeline "${infix}":`, err);
        });
        return s;
    });

    return merge(...streams); // <-- EIN Stream als Fertig‑Signal
};