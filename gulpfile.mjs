import pkg from './package.json' with { type: 'json' }; 

import pkgTiny from './node_modules/tiny-markdown-editor/package.json' with { type: 'json' };

import gulp from "gulp";

import size from "gulp-size";
import postcss from "gulp-postcss";
import concat from 'gulp-concat';
import terser from "gulp-terser";
import source from "vinyl-source-stream";
import buffer from "vinyl-buffer";
import log from 'fancy-log';

import rollupStream from "@rollup/stream";
import { babel as rollupBabel } from "@rollup/plugin-babel";
import { nodeResolve } from "@rollup/plugin-node-resolve";
import commonjs from "@rollup/plugin-commonjs";

import autoprefixer from "autoprefixer";
import cssnano from "cssnano";
import postcssUrl from 'postcss-url';

import { deleteAsync as del } from "del";

import * as fs from 'node:fs/promises';

import child_process from "node:child_process";
//import readline from "node:readline/promises";
//import process from "process";

//--- common consts
const VERSION_TXT = 'latest-version.txt';
const VERSION_PHP = 'LinkEnhancerModule.php';


//--- Rollup - javascript
const rollupConfig = (inputFile, sourcemaps = false) => {
    return {
        input: inputFile,
        output: {
            format: "iife",
            name: "LinkEnhMod",
            sourcemap: sourcemaps,
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

const jsPipe = (inputFile, outputInfix) =>
    rollupStream(rollupConfig(inputFile))
        .pipe(source(`bundle-${outputInfix}.min.js`))
        .pipe(buffer())
        .pipe(terser())
        .pipe(gulp.dest("./resources/js"))
        .pipe(size({ showFiles: true }));

const jsMde = () => jsPipe("./resources/js/tiny-mde-wt.js", 'mde');
const jsMdeLe = () => jsPipe("./resources/js/index-le-mde.js", 'le-mde');
const jsLe = () => jsPipe("./resources/js/linkenhancer.js", 'le');
const jscripts = gulp.series(jsMde, jsMdeLe, jsLe);

//--- CSS
const cssPipe = (inputFile, outputInfix) => 
    gulp
        .src(inputFile)
        .pipe(postcss([autoprefixer()]))
        .pipe(postcss([cssnano(), postcssUrl({
            url: 'inline',
            maxSize: 20,
            fallback: 'copy'
        })]))
        .pipe(concat(`bundle-${outputInfix}.min.css`))
        .pipe(gulp.dest("./resources/css"))
        .pipe(size({ showFiles: true }));

const cssMde = () => cssPipe(["./node_modules/tiny-markdown-editor/dist/tiny-mde.min.css", "./resources/css/tiny-mde-wt.css"], 'mde');
const cssMdeLe = () => cssPipe(["./node_modules/tiny-markdown-editor/dist/tiny-mde.min.css", "./resources/css/tiny-mde-wt.css", "./resources/css/linkenhancer.css"], 'le-mde');
const cssLe = () => cssPipe(["./resources/css/linkenhancer.css"], 'le');
const css = gulp.series(cssMde, cssMdeLe, cssLe);


//--- Version
const bumpVersion = () => {
    return execPromise("npm --no-git-tag-version version patch");
};

const syncVersion = async () => {
    const version = JSON.parse(await fs.readFile('./package.json', 'utf8')).version;

    let currentTxt = '';
    if (await fileExists(VERSION_TXT)) {
        currentTxt = await fs.readFile(VERSION_TXT, 'utf8');
        currentTxt = currentTxt.trim();
    }
    if (currentTxt !== version) {
        await fs.writeFile(VERSION_TXT, version, 'utf8');
        log(`${VERSION_TXT} update to ${version}`);
    } else {
        log(`${VERSION_TXT} - no change.`);
    }

    let currentPhp = '';
    if (await fileExists(VERSION_PHP)) {
        currentPhp = await fs.readFile(VERSION_PHP, 'utf8');
    }

    const phpRegex = /(public\s+const\s+CUSTOM_VERSION\s*=\s*["'])([\d.]+)(["']\s*;)/;
    const replacedPhp = currentPhp.replace(phpRegex, `$1${version}$3`);
    
    if (replacedPhp !== currentPhp) {
        await fs.writeFile(VERSION_PHP, replacedPhp, 'utf8');
        log(`${VERSION_PHP} updated to ${version}`);
    } else {
        log(`${VERSION_PHP} - no change.`);
    }
}

//--- Lang files
const updatepo = () => {
    return execPromise("./util/update-po-files.sh");
};

const createmo = () => {
    return execPromise("./util/convert-po2mo.sh");
};

//--- Utils
const fileExists = async path => !!(await fs.stat(path).catch(e => false));

const execPromise = (command) => {
    const cp = child_process.exec(command, { shell: "/bin/bash" });
    cp.stdout.on("data", (data) => {
        console.log(data.toString());
    });
    cp.stderr.on("data", (data) => {
        console.error(data.toString());
    });
    return new Promise((resolve, reject) => {
        cp.on("exit", (code) => (code ? reject(code) : resolve()));
    });
};

const gitCheckBranch = async () => {
    return new Promise((resolve, reject) => {
        child_process.exec("git branch --show-current", (err, stdout) => {
            if (err) reject(err);
            const branch = stdout.trim();
            if (branch === "main") {
                resolve();
            } else {
                reject(
                    `Releases can only be made from the main branch, current branch is ${branch}`
                );
            }
        });
    });
};

const gitCheckClean = async () => {
    return new Promise((resolve, reject) => {
        child_process.exec("git status -s", (err, stdout) => {
            if (err) reject(err);
            const status = stdout.trim();
            if (!status) {
                resolve();
            } else {
                reject(
                    `git repo is not clean: ${status}`
                );
            }
        });
    });
};

const createarchive = () => {
    return execPromise("./util/create-archive.sh");
};


const clean = () => del(["./resources/js/bundle*", "./resources/css/bundle*"]);

const build = gulp.series(clean, jscripts, css);
const bumpversion = gulp.series(gitCheckClean, bumpVersion, syncVersion);

const archive = gulp.series(build, createmo, createarchive);


export { build, bumpversion, updatepo, createmo, archive };