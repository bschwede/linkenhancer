/**
 * gulp/build file for webtrees custom module linkenhancer
 */
import gulp from "gulp"

import { deleteAsync as del } from "del"

import {
        jsExtractLeConfig,
        jsIndex,
        toggleDevMode
    } from "./resources/js/src/build/js-files.js"
import { getCssSubTasks } from "./resources/js/src/build/css-files.js"
import { convertPo2php } from "./resources/js/src/build/convert-po2php.js"
import { 
        bumpVersion,
        execPromise,
        gitCheckClean,
        syncVersion
    } from './resources/js/src/build/utils.js'

//import log from 'fancy-log';
//import pkg from './package.json' with { type: 'json' }; 
//import pkgTiny from './node_modules/tiny-markdown-editor/package.json' with { type: 'json' };
//import readline from "node:readline/promises";
//import process from "process";

//--- common consts
const VERSION_TXT = 'latest-version.txt';
const VERSION_PHP = 'src/LinkEnhancerModule.php';

// tasks:
//--- bundling js and css files
const jscripts = gulp.parallel(
    jsIndex,
    jsExtractLeConfig
)

const css = gulp.parallel(...getCssSubTasks())

const clean = () => del(["./resources/js/bundle*", "./resources/css/bundle*"])

const build = gulp.series(
    clean,
    jscripts,
    css
)

const devbuild = async () => {
    toggleDevMode(true)
    build()
}

//--- language files
const updatepo = () => {
    return execPromise("./util/update-po-files.sh")
}

const createmo = () => { // because of #88 Translations file format switch not necessary any more
    return execPromise("./util/convert-po2mo.sh")
}

const po2php = () => convertPo2php("./resources/lang")


//--- versioning
const syncversion = async () => await syncVersion(VERSION_TXT, VERSION_PHP)
const bumpversion = gulp.series(
    gitCheckClean, 
    bumpVersion, 
    syncversion
)


//--- prepare archive for release
const createarchive = () => {
    return execPromise("./util/create-archive.sh")
}
const archive = gulp.series(
    build,
    po2php,
    createarchive
)


// public tasks
export { 
    build,
    bumpversion,
    devbuild,
    syncversion,
    updatepo,
    po2php,
    archive
}