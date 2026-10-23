#!/bin/bash
# Pre-commit: PHPStan + PHP-Tests
# Install: ln -sf "$(git rev-parse --show-toplevel)/util/pre-commit.sh" "$(git rev-parse --show-toplevel)/.git/hooks/pre-commit"
#
# cwd is already modules_v4/linkenhancer in webtrees file tree (Bind-Mount).
# WT_ROOT (optional) for switching to another WT-Version.

if [[ -n "$WT_ROOT" ]]; then
    cd "$WT_ROOT/modules_v4/linkenhancer" || {
        echo "✗ WT_ROOT not available: $WT_ROOT"
        exit 1
    }
fi

if [[ ! -f ../../../vendor/autoload.php ]]; then
    echo "✗ No Webtrees context found (../../../vendor/autoload.php fehlt)."
    echo "  Commit must be run on bind mount path, e.g.:"
    echo "  cd /path/to/webtrees/modules_v4/linkenhancer && git commit -m \"...\""
    echo "  or: WT_ROOT=/path/to/webtrees git commit -m \"...\""
    exit 1
fi

echo "→ PHPStan..."
vendor/bin/phpstan analyse -c phpstan.neon --error-format=table || exit 1

echo "→ PHP-Tests..."
for f in tests/test-*.php; do
    php "$f" || exit 1
done

echo "✓ Pre-commit checks passed."
