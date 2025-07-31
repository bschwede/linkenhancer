//import packageConfig from './package.json' with { type: 'json' }; 

import pkgTiny from './node_modules/tiny-markdown-editor/package.json' with { type: 'json' };

import gulp from "gulp";

import size from "gulp-size";
import postcss from "gulp-postcss";
import concat from 'gulp-concat';
import terser from "gulp-terser";
import source from "vinyl-source-stream";
import buffer from "vinyl-buffer";

import rollupStream from "@rollup/stream";
import { babel as rollupBabel } from "@rollup/plugin-babel";
import { nodeResolve } from "@rollup/plugin-node-resolve";
import commonjs from "@rollup/plugin-commonjs";

import autoprefixer from "autoprefixer";
import cssnano from "cssnano";
import postcssUrl from 'postcss-url';

import { deleteAsync as del } from "del";


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


const jsMde = () =>
    rollupStream(rollupConfig("./resources/js/tiny-mde-wt.js"))
        .pipe(source("bundle-mde.min.js"))
        .pipe(buffer())
        .pipe(terser())
        .pipe(gulp.dest("./resources/js"))
        .pipe(size({ showFiles: true }));

const jsMdeLe = () =>
    rollupStream(rollupConfig("resources/js/index-le-mde.js")) 
        .pipe(source("bundle-le-mde.min.js"))
        .pipe(buffer())
        .pipe(terser())
        .pipe(gulp.dest("./resources/js"))
        .pipe(size({ showFiles: true }));

const jsLe = () =>
    rollupStream(rollupConfig("./resources/js/linkenhancer.js"))
        .pipe(source("bundle-le.min.js"))
        .pipe(buffer())
        .pipe(terser())
        .pipe(gulp.dest("./resources/js"))
        .pipe(size({ showFiles: true }));

const jscripts = gulp.series(jsMde, jsMdeLe, jsLe);


const cssConfig = (inputFile, outputInfix) => 
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

const cssMde = () => cssConfig(["./node_modules/tiny-markdown-editor/dist/tiny-mde.min.css", "./resources/css/tiny-mde-wt.css"], 'mde');

const cssMdeLe = () => cssConfig(["./node_modules/tiny-markdown-editor/dist/tiny-mde.min.css", "./resources/css/tiny-mde-wt.css", "./resources/css/linkenhancer.css"], 'le-mde');

const cssLe = () => cssConfig(["./resources/css/linkenhancer.css"], 'le');

const css = gulp.series(cssMde, cssMdeLe, cssLe);

const clean = () => del(["./resources/js/bundle*", "./resources/css/bundle*"]);



const build = gulp.series(clean, jscripts, css);
//const build = gulp.series(clean, jsMde);

export { build };