#!/usr/bin/env bash
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
    unit) exec vendor/bin/phpunit ;;
    functional) exec vendor/bin/phpunit -c Build/FunctionalTests.xml ;;
    phpstan) exec vendor/bin/phpstan analyse --no-progress ;;
    cgl) exec vendor/bin/php-cs-fixer check --diff ;;
    rector) exec vendor/bin/rector process --dry-run ;;
    fractor) exec vendor/bin/fractor process --dry-run ;;
    *) echo "Unknown suite: $suite" >&2; exit 2 ;;
esac
