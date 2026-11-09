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

lint_failed=0
while IFS= read -r file; do
    if ! line="$(php -l "${file}" 2>&1)"; then
        echo "${line}" >&2
        lint_failed=1
        continue
    fi
    if ! grep -Eq '^No syntax errors detected in .+' <<<"${line}"; then
        echo "php-lint: missing exact success line for ${file}" >&2
        echo "${line}" >&2
        lint_failed=1
    fi
done < <(find /app/src -type f -name '*.php' | sort)
if [[ ${lint_failed} -ne 0 ]]; then
    exit 1
fi
echo 'php-lint: No syntax errors detected in src/'

COMPOSER_URL="https://getcomposer.org/download/2.8.10/composer.phar"
COMPOSER_SHA256="28dbb6bd8bef31479c7985b774c130a8bda37dbe63c35b56f6cb6bc377427573"
curl -fsSL -o /tmp/composer.phar "${COMPOSER_URL}"
echo "${COMPOSER_SHA256}  /tmp/composer.phar" | sha256sum -c -

index_overlay="/app/.system-test-index"
mkdir -p "${index_overlay}"
php -r '
$url = "https://raw.githubusercontent.com/Training-Datasmith/composer-index/remote-test-2026-11-09/packages.json";
$raw = file_get_contents($url);
if ($raw === false) {
    fwrite(STDERR, "system-test: could not download packages.json\n");
    exit(1);
}
$data = json_decode($raw, true);
if (!is_array($data)) {
    fwrite(STDERR, "system-test: invalid packages.json\n");
    exit(1);
}
$want = "73208c4a426cbaef76adcd2eb1d8d2bac17ccbd2";
$data["packages"]["nyholm/psr7"]["dev-remote-test-2026-11-09"]["source"]["reference"] = $want;
if (isset($data["packages"]["nyholm/psr7"]["dev-remote-test-2026-11-09"]["dist"])) {
    $data["packages"]["nyholm/psr7"]["dev-remote-test-2026-11-09"]["dist"]["reference"] = $want;
}
file_put_contents("/app/.system-test-index/packages.json", json_encode($data));
'
php /tmp/composer.phar config --json repositories.training-index "{\"type\":\"composer\",\"url\":\"file://${index_overlay}\",\"canonical\":false}"
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

php scripts/check-legacy-names.php

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

php /tmp/composer.phar config --unset repositories.training-index 2>/dev/null || true

echo 'system-test: all steps passed.'
