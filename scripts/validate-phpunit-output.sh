#!/usr/bin/env bash

# Extract PHPUnit skipped-test identifiers (Class::method) from output.
# shellcheck disable=SC2155
collect_skipped_tests() {
  local output_file="$1"
  local out
  out="$(mktemp)"
  awk '
    /^There (was|were) [0-9]+ skipped tests?:$/ { inblock=1; next }
    inblock && /^[0-9]+\) / {
      line=$0
      sub(/^[0-9]+\) /, "", line)
      sub(/ with data provider.*$/, "", line)
      sub(/\[.*$/, "", line)
      gsub(/^[[:space:]]+|[[:space:]]+$/, "", line)
      if (line != "") print line
      next
    }
    inblock && $0 ~ /^$/ { next }
  ' "${output_file}" >"${out}"
  if [[ -s "${out}" ]]; then
    cat "${out}"
  fi
  rm -f "${out}"
}

validate_phpunit_output() {
  local output_file="$1"
  local allowlist="${2:-}"
  local expected_ok="${3:-}"
  local expected_summary="${4:-}"
  local stripped
  stripped="$(mktemp)"
  sed -E 's/\x1b\[[0-9;]*[[:alpha:]]//g' "${output_file}" >"${stripped}"
  output_file="${stripped}"
  # shellcheck disable=SC2064
  trap "rm -f '${stripped}'" RETURN

  if grep -Eq '^(PHP )?Warning:' "${output_file}"; then
    printf '%s\n' "PHPUnit output contains Warning:"
    grep -E '^(PHP )?Warning:' "${output_file}" || true
    return 1
  fi
  if grep -Eq '^(PHP )?Deprecated:' "${output_file}"; then
    printf '%s\n' "PHPUnit output contains Deprecated:"
    grep -E '^(PHP )?Deprecated:' "${output_file}" || true
    return 1
  fi
  if grep -Eq '^(PHP )?Notice:' "${output_file}"; then
    printf '%s\n' "PHPUnit output contains Notice:"
    grep -E '^(PHP )?Notice:' "${output_file}" || true
    return 1
  fi
  if grep -qE '^FAILURES!|^ERRORS!|^WARNINGS!' "${output_file}"; then
    printf '%s\n' "PHPUnit summary contains defect banner"
    return 1
  fi

  if [[ -n "${expected_ok}" ]]; then
    local want_ok
    if [[ -f "${expected_ok}" ]]; then
      want_ok="$(tr -d '\r' < "${expected_ok}" | sed '/^$/d' | tail -n 1)"
    else
      want_ok="${expected_ok}"
    fi
    if ! grep -Fxq "${want_ok}" "${output_file}"; then
      printf '%s\n' "PHPUnit output missing expected footer: ${want_ok}"
      return 1
    fi
  elif ! grep -Eq '^OK \([0-9]+ tests?, [0-9]+ assertions?\)$' "${output_file}"; then
    printf '%s\n' 'PHPUnit output is missing strict success line: OK (N tests, M assertions)'
    return 1
  fi

  if [[ -n "${expected_summary}" ]]; then
    local want_summary
    if [[ -f "${expected_summary}" ]]; then
      want_summary="$(tr -d '\r' < "${expected_summary}" | sed '/^$/d' | tail -n 1)"
    else
      want_summary="${expected_summary}"
    fi
    if ! grep -Fxq "${want_summary}" "${output_file}"; then
      printf '%s\n' "PHPUnit output missing expected summary: ${want_summary}"
      return 1
    fi
  fi

  if grep -qE 'Incomplete:' "${output_file}"; then
    printf '%s\n' "Incomplete tests are not allowed"
    return 1
  fi
  if grep -qE 'Risky:' "${output_file}"; then
    printf '%s\n' "Risky tests are not allowed"
    return 1
  fi

  local allowed=()
  if [[ -n "${allowlist}" && -f "${allowlist}" ]]; then
      while IFS= read -r line || [[ -n "${line}" ]]; do
        line="${line%%#*}"
        line="${line#"${line%%[![:space:]]*}"}"
        line="${line%"${line##*[![:space:]]}"}"
        [[ -z "${line}" ]] && continue
        allowed+=("${line}")
      done < "${allowlist}"
  fi

  mapfile -t found_skipped < <(collect_skipped_tests "${output_file}")
  local summary_skipped=0
  if grep -Eq '^Tests: [0-9]+, Assertions: [0-9]+, Skipped: [0-9]+' "${output_file}"; then
    summary_skipped="$(grep -Eo 'Skipped: [0-9]+' "${output_file}" | tail -n 1 | awk '{print $2}')"
  fi

  if [[ "${summary_skipped}" -gt 0 && ${#found_skipped[@]} -eq 0 ]]; then
    printf '%s\n' "PHPUnit reported Skipped: ${summary_skipped} but no skipped test identifiers were parsed"
    return 1
  fi

  local id
  for id in "${found_skipped[@]}"; do
    local ok=0
    local entry
    for entry in "${allowed[@]}"; do
      if [[ "${id}" == "${entry}" ]]; then
        ok=1
        break
      fi
    done
    if [[ "${ok}" -eq 0 ]]; then
      printf '%s\n' "Skipped test not on allowlist: ${id}"
      return 1
    fi
  done

  local entry
  for entry in "${allowed[@]}"; do
    local present=0
    for id in "${found_skipped[@]}"; do
      if [[ "${id}" == "${entry}" ]]; then
        present=1
        break
      fi
    done
    if [[ "${present}" -eq 0 ]]; then
      printf '%s\n' "Allowlisted skip did not occur: ${entry}"
      return 1
    fi
  done

  if [[ "${summary_skipped}" -eq 0 && ${#found_skipped[@]} -gt 0 ]]; then
    printf '%s\n' "Parsed skipped tests but summary shows none"
    return 1
  fi

  return 0
}

validate_phpunit_output_skip_demos() {
  local tmp allowlist
  tmp="$(mktemp)"
  allowlist="$(mktemp)"

  cat >"${allowlist}" <<'LIST'
Tests\Example::test_allowed_skip
LIST

  cat >"${tmp}" <<'OUT'
OK, but incomplete, skipped, or risky tests!
Tests: 3, Assertions: 2, Skipped: 1.

There was 1 skipped test:

1) Tests\Example::test_allowed_skip

OUT
  if ! validate_phpunit_output "${tmp}" "${allowlist}" "OK, but incomplete, skipped, or risky tests!" ""; then
    printf '%s\n' "validate-phpunit-output: allowlisted skip demo should pass"
    rm -f "${tmp}" "${allowlist}"
    return 1
  fi

  cat >"${tmp}" <<'OUT'
OK, but incomplete, skipped, or risky tests!
Tests: 3, Assertions: 2, Skipped: 1.

There was 1 skipped test:

1) Tests\Example::test_not_allowed

OUT
  if validate_phpunit_output "${tmp}" "${allowlist}" "OK, but incomplete, skipped, or risky tests!" ""; then
    printf '%s\n' "validate-phpunit-output: unlisted skip demo should fail"
    rm -f "${tmp}" "${allowlist}"
    return 1
  fi

  cat >"${tmp}" <<'OUT'
OK (3 tests, 3 assertions)
OUT
  if validate_phpunit_output "${tmp}" "${allowlist}" "OK (3 tests, 3 assertions)" ""; then
    printf '%s\n' "validate-phpunit-output: missing allowlisted skip demo should have failed"
    rm -f "${tmp}" "${allowlist}"
    return 1
  fi

  rm -f "${tmp}" "${allowlist}"
  printf '%s\n' "validate-phpunit-output: skip allowlist demos passed"
  return 0
}
