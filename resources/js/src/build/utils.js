/**
 * utility routines for gulp / build tasks
 */
import * as fs from 'node:fs/promises';
import child_process from "node:child_process";
import gulp from "gulp";
import path from "path";
import log from 'fancy-log';

import file from "gulp-file";
import { hashFileSync } from 'hasha';

export const fileExists = async path => !!(await fs.stat(path).catch(e => false));

export const execPromise = (command) => {
    const cp = child_process.exec(command, { shell: "/bin/bash" });
    cp.stdout.on("data", (data) => {
        log(data.toString());
    });
    cp.stderr.on("data", (data) => {
        log.error(data.toString());
    });
    return new Promise((resolve, reject) => {
        cp.on("exit", (code) => (code ? reject(code) : resolve()));
    });
};

export const bumpVersion = () => {
    return execPromise("npm --no-git-tag-version version patch");
};

export const syncVersion = async (txt_file, php_file) => {
    const version = JSON.parse(await fs.readFile('./package.json', 'utf8')).version;

    let currentTxt = '';
    if (await fileExists(txt_file)) {
        currentTxt = await fs.readFile(txt_file, 'utf8');
        currentTxt = currentTxt.trim();
    }
    if (currentTxt !== version) {
        await fs.writeFile(txt_file, version, 'utf8');
        log(`${txt_file} update to ${version}`);
    } else {
        log(`${txt_file} - no change.`);
    }

    let currentPhp = '';
    if (await fileExists(php_file)) {
        currentPhp = await fs.readFile(php_file, 'utf8');
    }

    const phpRegex = /(public\s+const\s+CUSTOM_VERSION\s*=\s*["'])([\d.]+)(["']\s*;)/;
    const replacedPhp = currentPhp.replace(phpRegex, `$1${version}$3`);
    
    if (replacedPhp !== currentPhp) {
        await fs.writeFile(php_file, replacedPhp, 'utf8');
        log(`${php_file} updated to ${version}`);
    } else {
        log(`${php_file} - no change.`);
    }
}


// --- not in use ---
export const hashFile = async (filepath) => {
    const hashValue = hashFileSync(filepath, { algorithm: 'sha256' });
    const destpath = path.dirname(filepath);
    const hashfile = path.basename(filepath) + '.hash';

    return file(hashfile, hashValue)
        .pipe(gulp.dest(destpath));
}


export const gitCheckBranch = async () => {
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

export const gitCheckClean = async () => {
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