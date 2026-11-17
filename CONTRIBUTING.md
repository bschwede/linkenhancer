# CONTRIBUTING

> **Detailed developer documentation** (setup, build system, testing, translation pipeline, release process, project structure):
> <https://bschwede.codeberg.page/wt-modules/en/docs/linkenhancer/dev/>

## To contribute

1. Go to primary repository: <https://codeberg.org/bschwede/linkenhancer>
2. Create an account on Codeberg (it's free and FOSS!)
3. Fork the repository there
4. Make your changes
5. Open a Pull Request on Codeberg

## Already made changes on GitHub?

No problem! You can push your branch to Codeberg:

```bash
git remote add codeberg https://codeberg.org/bschwede/linkenhancer.git
git push codeberg your-branch-name
```

Then open a PR via the Codeberg web interface.

## Quick reference

| Task | Where |
|------|-------|
| Dev environment setup | [dev-environment](https://bschwede.codeberg.page/wt-modules/en/docs/linkenhancer/dev/dev-environment/) |
| Build system (gulp tasks) | [build-system](https://bschwede.codeberg.page/wt-modules/en/docs/linkenhancer/dev/build-system/) |
| Testing | [testing](https://bschwede.codeberg.page/wt-modules/en/docs/linkenhancer/dev/testing/) |
| Translation pipeline | [translation-pipeline](https://bschwede.codeberg.page/wt-modules/en/docs/linkenhancer/dev/translation-pipeline/) |
| PHPStan | [phpstan](https://bschwede.codeberg.page/wt-modules/en/docs/linkenhancer/dev/phpstan/) |
| CLI scripts | [cli-scripts](https://bschwede.codeberg.page/wt-modules/en/docs/linkenhancer/dev/cli-scripts/) |
| Release process | [release](https://bschwede.codeberg.page/wt-modules/en/docs/linkenhancer/dev/release/) |
| Project structure | [project-structure](https://bschwede.codeberg.page/wt-modules/en/docs/linkenhancer/dev/project-structure/) |

## Dependencies

This module requires shared code from [wt-shared-libs](https://codeberg.org/bschwede/wt-shared-libs). Clone that repo into a folder named `wt-shared-libs` beside this module, then run `composer update`.
