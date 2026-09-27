package main

import (
	"os"
	"regexp"
	"testing"
)

// A release job that generates only release.go fails here, rather than tagging a Composer package
// that still says it is unreleased.
func TestTheComposerReleaseMatchesTheGoRelease(t *testing.T) {
	source, err := os.ReadFile("composer/src/Release.php")
	if err != nil {
		t.Fatal(err)
	}
	match := regexp.MustCompile(`const VERSION = '([^']*)';`).FindSubmatch(source)
	if match == nil {
		t.Fatalf("composer/src/Release.php declares no VERSION:\n%s", source)
	}
	if string(match[1]) != version {
		t.Fatalf("composer/src/Release.php is for %q, release.go for %q", match[1], version)
	}
}
