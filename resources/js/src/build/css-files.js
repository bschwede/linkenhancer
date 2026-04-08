/*
bundle css files
*/
import gulp from "gulp";
import postcss from "gulp-postcss";
import concat from 'gulp-concat';
import size from "gulp-size";
import autoprefixer from "autoprefixer";
import cssnano from "cssnano";
import postcssUrl from 'postcss-url';
import { generateKeyCombinations } from './combinations.js';

const cssComponents = {
    'le': "./resources/css/index-le.css",
    'img': "./resources/css/index-img.css",
    'mde': ["./node_modules/tiny-markdown-editor/dist/tiny-mde.min.css", "./resources/css/index-mde.css"],
    'wthb': "./resources/css/index-wthb.css",
}


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


export const getCssSubTasks = () => {
    const createCssTask = (comboKey, sources) => {
        const taskName = `css:${comboKey}`;
        gulp.task(taskName, () => cssPipe(sources, comboKey));
        return taskName;
    };

    const cssFuncs = []
    return Object.entries(generateKeyCombinations(cssComponents)).map(([comboKey, sources]) =>
        createCssTask(comboKey, sources)
    );
}
// const css = gulp.parallel(...getCssSubTasks());