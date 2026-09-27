#!/usr/bin/env bash
# Installs this checkout the way Composer users get it, with Release.php generated from a real
# bonsai-lint release, then checks it: bash composer/tests/smoke.sh vX.Y.Z
set -euo pipefail

tag="$1"
root="$(cd "$(dirname "$0")/../.." && pwd)"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
mkdir "$work/package" "$work/project"

# A path repository copies whatever it is pointed at, so give it the tracked tree and nothing built.
(cd "$root" && git ls-files -co --exclude-standard -z | tar --null -T - -cf -) | tar -xf - -C "$work/package"
curl -fsSL -o "$work/dist-manifest.json" "https://github.com/ryckakas/bonsai-lint/releases/download/$tag/dist-manifest.json"
(cd "$root" && go run ./internal/generate -manifest "$work/dist-manifest.json" -out "$work/release.go" -php "$work/package/composer/src/Release.php")

cat > "$work/project/composer.json" <<'JSON'
{
    "repositories": [{"type": "path", "url": "../package", "options": {"symlink": false}}],
    "require-dev": {"bonsai-lint/bonsai-lint": "*@dev"}
}
JSON
(cd "$work/project" && composer install --no-interaction --no-progress --quiet)

bash "$root/composer/tests/check.sh" "$work/project" "${tag#v}"
case "$(uname -s)" in
    MINGW* | MSYS* | CYGWIN*) pwsh -NoProfile -File "$(cygpath -w "$root/composer/tests/check.ps1")" "$(cygpath -w "$work/project")" ;;
esac
echo "smoke: $tag installs and runs through Composer"
