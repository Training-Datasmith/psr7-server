#!/usr/bin/env bash
set -euo pipefail

export COMPOSER_ALLOW_SUPERUSER=1
git config --global --add safe.directory /app 2>/dev/null || true

fail_deprecation_output() {
    local label="$1"
    local file="$2"
    local stripped
    stripped="$(mktemp)"
    sed -E 's/\x1b\[[0-9;]*[[:alpha:]]//g' "${file}" >"${stripped}"
    if grep -E '^(PHP )?(Warning|Deprecated|Notice):' "${stripped}" >/dev/null; then
        echo "${label}: output contains Warning, Deprecated, or Notice lines" >&2
        grep -E '^(PHP )?(Warning|Deprecated|Notice):' "${stripped}" >&2 || true
        rm -f "${stripped}"
        exit 1
    fi
    rm -f "${stripped}"
}

php -v | tee /tmp/php-version.txt
php -r '
$v = PHP_VERSION;
if (version_compare($v, "7.2.34", "<") || version_compare($v, "7.3.0", ">=")) {
    fwrite(STDERR, "PHP {$v} is not the required PHP 7.2.34 floor\n");
    exit(1);
}
'

for ext in mbstring dom json xml xmlwriter tokenizer phar ctype; do
    if ! php -m | grep -qi "^${ext}$"; then
        echo "Missing required PHP extension: ${ext}" >&2
        exit 1
    fi
done

COMPOSER_URL="https://getcomposer.org/download/2.8.10/composer.phar"
COMPOSER_SHA256="28dbb6bd8bef31479c7985b774c130a8bda37dbe63c35b56f6cb6bc377427573"
curl -fsSL -o /tmp/composer.phar "${COMPOSER_URL}"
echo "${COMPOSER_SHA256}  /tmp/composer.phar" | sha256sum -c -

composer_out="$(mktemp)"
set +e
php /tmp/composer.phar update --no-interaction --prefer-dist --no-progress --no-ansi >"${composer_out}" 2>&1
composer_status=$?
set -e
cat "${composer_out}"
fail_deprecation_output "composer update" "${composer_out}"
if [[ ${composer_status} -ne 0 ]]; then
    rm -f "${composer_out}"
    exit "${composer_status}"
fi
if ! grep -Fxq 'Generating autoload files' "${composer_out}"; then
    echo 'composer update: missing exact success line Generating autoload files' >&2
    exit 1
fi
rm -f "${composer_out}"

php scripts/check-cluster-pins.php

audit_out="$(mktemp)"
set +e
php /tmp/composer.phar audit --no-interaction --abandoned=report >"${audit_out}" 2>&1
audit_status=$?
set -e
cat "${audit_out}"
fail_deprecation_output "composer audit" "${audit_out}"
if [[ ${audit_status} -ne 0 ]]; then
    exit "${audit_status}"
fi
if ! grep -Fxq 'No security vulnerability advisories found.' "${audit_out}"; then
    echo 'composer audit: missing exact success line' >&2
    exit 1
fi
rm -f "${audit_out}"

php scripts/check-rename.php

phpunit_out="$(mktemp)"
set +e
php -d error_reporting=32767 vendor/bin/phpunit --colors=never >"${phpunit_out}" 2>&1
phpunit_status=$?
set -e
cat "${phpunit_out}"
fail_deprecation_output "phpunit" "${phpunit_out}"
if [[ ${phpunit_status} -ne 0 ]]; then
    exit "${phpunit_status}"
fi

# shellcheck source=scripts/validate-phpunit-output.sh
source /app/scripts/validate-phpunit-output.sh

if ! validate_phpunit_output_skip_demos; then
    exit 1
fi

if ! validate_phpunit_output "${phpunit_out}" "/app/scripts/allowed-skipped-tests.txt" \
    "/app/scripts/expected-phpunit-ok.txt" ""; then
    exit 1
fi

want_ok="$(tr -d '\r' < /app/scripts/expected-phpunit-ok.txt | sed '/^$/d' | tail -n1)"
echo "Strict footer line: ${want_ok}"

vendor/bin/phpunit --version | head -n1
rm -f "${phpunit_out}"

echo 'system-test: all steps passed.'
