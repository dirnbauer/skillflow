#!/usr/bin/env bash
# Local quality gates. Mirrors .github/workflows/ci.yml:
#   Build/Scripts/runTests.sh -s lint|cgl|phpstan|unit|functional|rector|fractor [-p 8.4]
set -euo pipefail
cd "$(dirname "$0")/../.."
suite=unit
php_version=
while getopts 's:p:' option; do
    case "$option" in
        s) suite="$OPTARG" ;;
        p) php_version="$OPTARG" ;;
        *) exit 2 ;;
    esac
done
if [[ -n "$php_version" && "$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')" != "$php_version" ]]; then
    echo "Run this command in the requested PHP $php_version environment." >&2
    exit 1
fi
case "$suite" in
    lint) find Classes Configuration Tests Build/phpunit ext_localconf.php -name '*.php' -print0 | xargs -0 -n1 -P4 php -l >/dev/null && echo "Lint OK" ;;
    cgl) exec vendor/bin/php-cs-fixer check --diff ;;
    cgl:fix) exec vendor/bin/php-cs-fixer fix ;;
    phpstan) exec vendor/bin/phpstan analyse --no-progress ;;
    unit) exec vendor/bin/phpunit -c Build/phpunit/UnitTests.xml ;;
    functional) exec vendor/bin/phpunit -c Build/phpunit/FunctionalTests.xml ;;
    rector) exec vendor/bin/rector process --dry-run ;;
    fractor) exec vendor/bin/fractor process --dry-run ;;
    *) echo "Unknown suite: $suite" >&2; exit 2 ;;
esac
