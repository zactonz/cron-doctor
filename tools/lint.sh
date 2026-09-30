#!/bin/bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

FAILED=0

step() {
    printf '\n\033[1m%s\033[0m\n' "$1"
}

step "PHP syntax"
while IFS= read -r file; do
    php -l "$file" > /dev/null || FAILED=1
done < <(find . -path ./.git -prune -o -name '*.php' -print)
printf 'all PHP files parse\n'

step "Shell scripts"
for script in install.sh uninstall.sh tools/lint.sh; do
    [ -f "$script" ] || continue

    shellcheck "$script" || FAILED=1
    bash -n "$script" || FAILED=1
done

for script in payload/bin/zcd-run payload/bin/zcd-sentinel \
              tests/shell/runner-tests.sh tests/fixtures/fake-crontab; do
    [ -f "$script" ] || continue

    shellcheck -s sh "$script" || FAILED=1
    sh -n "$script" || FAILED=1
    bash -n "$script" || FAILED=1

    if command -v dash > /dev/null 2>&1; then
        dash -n "$script" || FAILED=1
    fi
done
printf 'all shell scripts pass shellcheck and parse\n'

step "Source policy"
php tools/lint.php || FAILED=1

step "PHP tests"
php tests/run.php || FAILED=1

step "Runner tests"
sh tests/shell/runner-tests.sh || FAILED=1

if [ "$FAILED" -ne 0 ]; then
    printf '\n\033[41;37m LINT FAILED \033[0m\n'
    exit 1
fi

printf '\n\033[42;30m ALL CHECKS PASSED \033[0m\n'
