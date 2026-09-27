# bonsai-lint launchers

[bonsai-lint](https://bonsai.kauneckas.dev) is a multi-language cognitive complexity linter,
written in Rust. This repository holds its launchers for the ecosystems that install straight from
a git repository:
- the Composer package `bonsai-lint/bonsai-lint`;
- the Go module `bonsai.kauneckas.dev/bonsai-lint`.

Each is a small launcher with no dependencies. The first run of a version downloads that
release's prebuilt binary for your platform from GitHub Releases, checks it against the sha256
recorded in the launcher's own tagged source, and caches it. Every run then starts that binary
with your arguments, so stdout, stdin and the exit code are bonsai-lint's own.

## Composer

```bash
composer require --dev bonsai-lint/bonsai-lint     # then: vendor/bin/bonsai-lint src/
```

- **What it needs:** PHP 7.4 or newer, with the openssl and zlib extensions and
  `allow_url_fopen`, which nearly every PHP build has. There is no Composer plugin to allow and
  no other package to install. If something is missing, the launcher names the setting to change.
- **Where the binary goes:** inside the package in `vendor/`, at
  `vendor/bonsai-lint/bonsai-lint/composer/cache/<version>/<platform>/`.
  - Removing `vendor/` removes the binary, and updating to a new version downloads that version's.
  - In CI, cache `vendor/`, or set `BONSAI_LINT_CACHE` to a cached path, to skip the 2 MB
    download on each run.
  - In a Docker image, run `vendor/bin/bonsai-lint --version` once while building it.
- **How it runs:** where PHP has pcntl (Homebrew, Debian and Ubuntu's php-cli), the binary
  replaces the PHP process, so its signals and exit code are native. Elsewhere, for example
  official Docker images and Windows, the launcher runs it and passes on its exit code.
  - On Windows, run it from cmd or PowerShell through Composer's `vendor\bin\bonsai-lint.bat`.
- **Proxies:** `https_proxy`, `http_proxy` and `no_proxy`, in either case, are read the way
  Composer reads them. Downloads never send a GitHub or Composer token; proxy credentials go only
  to the proxy.
- **A locked install always runs the same binary:** `composer.lock` pins the launcher's commit,
  and that commit pins the checksums.

## Go

```bash
go install bonsai.kauneckas.dev/bonsai-lint@latest      # then: bonsai-lint ./...
```

```bash
go get -tool bonsai.kauneckas.dev/bonsai-lint@latest    # Go 1.24+, pinned in go.mod
go tool bonsai-lint .
```

- `go run bonsai.kauneckas.dev/bonsai-lint@latest .` works too.
- `go get -tool` adds exactly one line to your `go.mod`, because the launcher has no
  dependencies.
- On macOS and Linux the binary replaces the launcher process. On Windows the launcher runs it
  and passes on its exit code.
- Go's checksum database fixes each version's source, and so the checksums it carries.

## Platforms

| Platform | Composer | Go |
| --- | --- | --- |
| macOS arm64, x86_64 | macOS build | macOS build |
| Linux x86_64, arm64 | static build, which runs on any Linux | glibc build on glibc 2.35 or newer, static build otherwise |
| Windows x64 | Windows build | Windows build |
| Windows on ARM | the x64 build, which Windows 11 on ARM runs under emulation | the same |

- **The Go launcher picks its Linux build** from `ldd --version`, on the first run of a version.
  The Composer launcher always takes the static one, as bonsai-lint's PyPI wheels do.
- **Other platforms fail with an explanation.** Build bonsai-lint there with
  `cargo install bonsai-lint`, and point `BONSAI_LINT_BINARY` at the result.
- **A native Windows on ARM build** is on the
  [roadmap](https://github.com/ryckakas/bonsai-lint/blob/main/ROADMAP.md).

## Settings

Both launchers read the same variables:

| Variable | Effect |
| --- | --- |
| `BONSAI_LINT_BINARY` | Run this binary instead of downloading one. For offline machines, or a build of your own. |
| `BONSAI_LINT_DOWNLOAD_URL` | Download from this base URL instead of the GitHub release, e.g. an internal mirror holding the same archive names. Archives are still checked against the recorded checksums. dist's shell and PowerShell installers read the same variable. |
| `BONSAI_LINT_CACHE` | The cache directory, laid out as `<version>/<platform>/`, so one directory can serve both launchers. |

**The default cache:**
- **Composer:** `composer/cache` inside the package.
- **Go:** `bonsai-lint` inside the user cache directory:
  - `~/Library/Caches` on macOS;
  - `$XDG_CACHE_HOME` or `~/.cache` on Linux;
  - `%LocalAppData%` on Windows.
- **Old versions:** each version keeps its own directory, because different projects can pin
  different versions. Delete old ones whenever you like.

## Versions

A launcher's version is always bonsai-lint's: `v0.4.3` of either one runs bonsai-lint 0.4.3.

Each tag is created by bonsai-lint's release pipeline, after that release's binaries are
published. The pipeline:
1. writes the release's checksums into `release.go` and `composer/src/Release.php`;
2. runs both launchers' tests;
3. downloads and runs the real release through both launchers;
4. tags this repository.

A version is never re-tagged. The next release carries any fix.

## Development

```bash
go vet ./... && go test ./...
php composer/tests/run.php               # the Composer launcher's tests, on PHP 7.4 and newer
composer validate --strict
bash composer/tests/smoke.sh v0.4.2      # installs this checkout through Composer and runs a real release
```

CI's Lint and Workflow security jobs also run these. The tools are pinned to exact releases in
`ci.yml`:

```bash
gofmt -l .                               # prints nothing when formatted
phpstan analyse                          # phpstan.neon: level 8, as PHP 7.4
shellcheck composer/tests/*.sh
typos
bonsai-lint .                            # the launchers meet bonsai-lint's default threshold too
zizmor .github
```

- **On `main`, both release files are placeholders,** so a launcher built from a checkout has
  nothing to download. Run it with `BONSAI_LINT_BINARY`, or let the tests cover it.
  `TestTheComposerReleaseMatchesTheGoRelease` keeps the two files on the same release.
- **The release commits are machine-written.** The `release vX.Y.Z` commits and tags come from
  bonsai-lint's release pipeline, and `internal/generate` writes both release files from that
  release's `dist-manifest.json`.
- **What gets tagged:** at each bonsai-lint release, whatever is on `main` is tagged together
  with that release's generated files. Land launcher changes only when they are ready to ship.

MIT licensed, like bonsai-lint.
