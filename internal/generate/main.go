// Command generate writes the launchers' release files from the dist-manifest.json of a
// bonsai-lint release, so each tagged version carries the checksums of the exact archives it may
// download: release.go for the Go module and, with -php, Release.php for the Composer package.
//
//	go run ./internal/generate -manifest dist-manifest.json -php composer/src/Release.php
//	go run ./internal/generate -placeholder -php composer/src/Release.php
package main

import (
	"bytes"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"go/format"
	"os"
	"regexp"
	"sort"
	"strings"
)

// The GOOS/GOARCH pairs the release covers, each mapped to the dist target it downloads. A
// "/musl" key is the static build, for the Linux hosts the glibc build cannot run on.
var platforms = map[string]string{
	"darwin/amd64":     "x86_64-apple-darwin",
	"darwin/arm64":     "aarch64-apple-darwin",
	"linux/amd64":      "x86_64-unknown-linux-gnu",
	"linux/amd64/musl": "x86_64-unknown-linux-musl",
	"linux/arm64":      "aarch64-unknown-linux-gnu",
	"linux/arm64/musl": "aarch64-unknown-linux-musl",
	"windows/amd64":    "x86_64-pc-windows-msvc",
	// No native build yet; Windows 11 on ARM runs the x64 binary under emulation.
	"windows/arm64": "x86_64-pc-windows-msvc",
}

type manifest struct {
	AnnouncementTag string `json:"announcement_tag"`
	Releases        []struct {
		AppName    string `json:"app_name"`
		AppVersion string `json:"app_version"`
	} `json:"releases"`
	Artifacts map[string]artifact `json:"artifacts"`
}

type artifact struct {
	Name          string            `json:"name"`
	Kind          string            `json:"kind"`
	TargetTriples []string          `json:"target_triples"`
	Checksums     map[string]string `json:"checksums"`
	Assets        []struct {
		Kind string `json:"kind"`
		Path string `json:"path"`
	} `json:"assets"`
}

var sha256Hex = regexp.MustCompile(`^[0-9a-f]{64}$`)

func main() {
	manifestPath := flag.String("manifest", "", "the released dist-manifest.json")
	out := flag.String("out", "release.go", "the file to write")
	php := flag.String("php", "", "also write the Composer launcher's Release.php here")
	placeholder := flag.Bool("placeholder", false, "write the unreleased placeholder instead")
	flag.Parse()

	var version string
	var entries []entry
	var err error
	switch {
	case *placeholder:
	case *manifestPath != "":
		version, entries, err = fromManifest(*manifestPath)
	default:
		err = errors.New("pass -manifest or -placeholder")
	}
	if err == nil {
		err = write(*out, *php, version, entries)
	}
	if err != nil {
		fmt.Fprintln(os.Stderr, "generate:", err)
		os.Exit(1)
	}
}

// Only an explicit -php writes Release.php: a caller that does not know about it leaves the
// placeholder, and TestTheComposerReleaseMatchesTheGoRelease then stops the tag.
func write(out, php, version string, entries []entry) error {
	source, err := render(version, entries)
	if err != nil {
		return err
	}
	if err := os.WriteFile(out, source, 0o644); err != nil {
		return err
	}
	if php == "" {
		return nil
	}
	return os.WriteFile(php, renderPHP(version, entries), 0o644)
}

func fromManifest(path string) (string, []entry, error) {
	text, err := os.ReadFile(path)
	if err != nil {
		return "", nil, err
	}
	var m manifest
	if err := json.Unmarshal(text, &m); err != nil {
		return "", nil, fmt.Errorf("%s: %w", path, err)
	}
	version, entries, err := archives(m)
	if err != nil {
		return "", nil, fmt.Errorf("%s: %w", path, err)
	}
	return version, entries, nil
}

type entry struct {
	platform, triple, name, sha256, binary string
}

func archives(m manifest) (string, []entry, error) {
	version := ""
	for _, release := range m.Releases {
		if release.AppName == "bonsai-lint" {
			version = release.AppVersion
		}
	}
	if version == "" {
		return "", nil, errors.New("no bonsai-lint release in the manifest")
	}
	// The Go module is tagged with this version, so it must be the tag the release was cut from.
	if m.AnnouncementTag != "v"+version {
		return "", nil, fmt.Errorf("tag %q does not match version %s", m.AnnouncementTag, version)
	}

	// In a fixed order, so a release missing several archives always reports the same one.
	var names []string
	for platform := range platforms {
		names = append(names, platform)
	}
	sort.Strings(names)
	var entries []entry
	for _, platform := range names {
		found, err := archiveFor(m, platforms[platform])
		if err != nil {
			return "", nil, err
		}
		found.platform = platform
		entries = append(entries, found)
	}
	return version, entries, nil
}

func archiveFor(m manifest, triple string) (entry, error) {
	for _, a := range m.Artifacts {
		if a.Kind == "executable-zip" && len(a.TargetTriples) == 1 && a.TargetTriples[0] == triple {
			return entryFor(a, triple)
		}
	}
	return entry{}, fmt.Errorf("no archive for %s", triple)
}

func entryFor(a artifact, triple string) (entry, error) {
	wantSuffix := ".tar.gz"
	if strings.Contains(triple, "windows") {
		wantSuffix = ".zip"
	}
	// The standard library has no xz reader, and the launcher takes no dependencies.
	if !strings.HasSuffix(a.Name, wantSuffix) {
		return entry{}, fmt.Errorf("%s: the launcher needs a %s archive for %s (set unix-archive in dist-workspace.toml)",
			a.Name, wantSuffix, triple)
	}
	sum := strings.ToLower(a.Checksums["sha256"])
	if !sha256Hex.MatchString(sum) {
		return entry{}, fmt.Errorf("%s: no sha256 checksum", a.Name)
	}
	binary := executableIn(a)
	if binary == "" {
		return entry{}, fmt.Errorf("%s: no executable listed", a.Name)
	}
	return entry{triple: triple, name: a.Name, sha256: sum, binary: binary}, nil
}

func executableIn(a artifact) string {
	for _, asset := range a.Assets {
		if asset.Kind == "executable" {
			return asset.Path
		}
	}
	return ""
}

func render(version string, entries []entry) ([]byte, error) {
	var b bytes.Buffer
	b.WriteString("// Code generated by internal/generate. DO NOT EDIT.\n\npackage main\n\n")
	if version == "" {
		b.WriteString("// An unreleased launcher: the release job replaces this file when it tags a version.\n")
	}
	fmt.Fprintf(&b, "const version = %q\n\nvar archives = map[string]archive{\n", version)
	for _, e := range entries {
		fmt.Fprintf(&b, "%q: {triple: %q, name: %q, sha256: %q, binary: %q},\n",
			e.platform, e.triple, e.name, e.sha256, e.binary)
	}
	b.WriteString("}\n")
	return format.Source(b.Bytes())
}

// The Composer launcher picks its own triple (always musl on Linux), so it gets every archive the
// Go launcher does, keyed by triple rather than by GOOS/GOARCH.
func renderPHP(version string, entries []entry) []byte {
	var b bytes.Buffer
	b.WriteString("<?php\n\n// Code generated by internal/generate. DO NOT EDIT.\n\ndeclare(strict_types=1);\n\nnamespace BonsaiLint\\Composer;\n\n")
	if version == "" {
		b.WriteString("// An unreleased launcher: the release job replaces this file when it tags a version.\n")
	}
	fmt.Fprintf(&b, "final class Release\n{\n    public const VERSION = %s;\n\n", phpString(version))
	byTriple := map[string]entry{}
	var triples []string
	for _, e := range entries {
		if _, seen := byTriple[e.triple]; !seen {
			triples = append(triples, e.triple)
		}
		byTriple[e.triple] = e
	}
	sort.Strings(triples)
	if len(triples) == 0 {
		b.WriteString("    public const ARCHIVES = [];\n}\n")
		return b.Bytes()
	}
	b.WriteString("    public const ARCHIVES = [\n")
	for _, triple := range triples {
		e := byTriple[triple]
		fmt.Fprintf(&b, "        %s => [%s, %s],\n", phpString(triple), phpString(e.name), phpString(e.sha256))
	}
	b.WriteString("    ];\n}\n")
	return b.Bytes()
}

func phpString(s string) string {
	return "'" + strings.NewReplacer(`\`, `\\`, `'`, `\'`).Replace(s) + "'"
}
