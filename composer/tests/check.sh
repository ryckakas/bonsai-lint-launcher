#!/usr/bin/env bash
# Runs an installed bonsai-lint/bonsai-lint the way users do: bash check.sh <project> <version>
# The smoke test, the release job and published.yml all end here.
set -euo pipefail

cd "$1"
version="$2"

fail() {
    echo "check: $*" >&2
    exit 1
}

expect() {
    local want="$1"
    shift
    set +e
    "$@" > out.txt 2> err.txt
    local got=$?
    set -e
    if [ "$got" != "$want" ]; then
        cat out.txt err.txt >&2
        fail "$* exited $got, expected $want"
    fi
}

printf 'export function busy(a: number) {\n  if (a) { for (const b of [a]) { if (b) { return b } } }\n  return 0\n}\n' > busy.ts

expect 0 vendor/bin/bonsai-lint --version
[ "$(cat out.txt)" = "bonsai-lint $version" ] || fail "the first run printed $(cat out.txt)"
grep -q "^bonsai-lint: downloading v$version for " err.txt || fail "the first run did not report its download"
ls vendor/bonsai-lint/bonsai-lint/composer/cache/"$version"/*/bonsai-lint* > /dev/null || fail "no binary in the package's cache"

expect 0 vendor/bin/bonsai-lint --version
[ ! -s err.txt ] || fail "the second run was not silent: $(cat err.txt)"

for disabled in "" "pcntl_exec"; do
    php=(php -d "disable_functions=$disabled")
    expect 1 "${php[@]}" vendor/bin/bonsai-lint --over 1 busy.ts
    grep -q 'busy.ts:1  busy' out.txt || fail "no finding for busy.ts: $(cat out.txt)"
    expect 2 "${php[@]}" vendor/bin/bonsai-lint --no-such-flag
    expect 1 "${php[@]}" vendor/bin/bonsai-lint --stdin --stdin-path 'we%ird !dir/a&b"c.ts' --over 1 --format json < busy.ts
    grep -qF '"path": "we%ird !dir/a&b\"c.ts"' out.txt || fail "the stdin path did not survive: $(cat out.txt)"
done

expect 0 vendor/bin/bonsai-lint --lang php vendor/bonsai-lint/bonsai-lint/composer
echo "check: bonsai-lint $version runs through vendor/bin"
