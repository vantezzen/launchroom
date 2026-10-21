package browser

import "testing"

func TestCommand(t *testing.T) {
	u := "https://lr.example.com/cli/login?code=AAAA-BBBB&x=$(id)"
	for goos, want := range map[string]string{"darwin": "open", "linux": "xdg-open", "windows": "rundll32"} {
		name, args := Command(goos, u)
		if name != want || args[len(args)-1] != u {
			t.Errorf("%s: %s %v", goos, name, args)
		}
	}
}

func TestOpenRefusesOtherSchemes(t *testing.T) {
	for _, u := range []string{"file:///etc/passwd", "javascript:alert(1)", "-a Calculator", ""} {
		if Open(u) == nil {
			t.Errorf("Open(%q) must refuse", u)
		}
	}
}
