package state

import (
	"crypto/rand"
	"crypto/rsa"
	"encoding/base64"
	"strings"
	"testing"

	"golang.org/x/crypto/ssh"
)

func TestGitURLGrammar(t *testing.T) {
	ok := []GitURL{
		"https://github.com/acme/shop.git",
		"http://git-fixtures:8080/node-hello.git",
		"https://gitlab.example.com/group/sub/app.git",
		"ssh://git@github.com/acme/shop.git",
		"ssh://git.example.com:2222/srv/app.git",
		"ssh://deploy_user@10.0.0.5/~deploy/app.git",
		"ssh://git@git.example.com/~/repos/app.git",
	}
	for _, u := range ok {
		if err := u.Validate(); err != nil {
			t.Errorf("%s: %v", u, err)
		}
	}
	bad := []GitURL{
		"ssh://git:pw@github.com/a/b",
		"ssh://git@github.com",
		"ssh://git@github.com/",
		"ssh://git@github.com/a'b",
		"ssh://git@github.com/a;b",
		"ssh://git@github.com/a%20b",
		"ssh://git@github.com/a+b",
		"ssh://Git@github.com/a",
		"ssh://git@GitHub.com/a",
		"ssh://@github.com/a",
		"git@github.com:acme/shop.git",
		"ssh://git@github.com/a?x=1",
		"https://user@github.com/a",
		"file:///etc",
		"git://github.com/a",
	}
	for _, u := range bad {
		if err := u.Validate(); err == nil {
			t.Errorf("%s accepted", u)
		}
	}
	if !GitURL("ssh://git@h/a").IsSSH() || GitURL("https://h/a").IsSSH() {
		t.Error("IsSSH")
	}
}

func TestGitRefGrammar(t *testing.T) {
	for _, r := range []GitRef{"refs/heads/main", "refs/heads/feature/x-1", "refs/tags/v1.2.0"} {
		if err := r.Validate(); err != nil {
			t.Errorf("%s: %v", r, err)
		}
	}
	for _, r := range []GitRef{"main", "refs/pull/1/head", "refs/heads/a..b", "refs/heads/.hidden", "refs/heads/x.lock", "refs/heads/a//b", "refs/heads/a/", "refs/heads/a b", "refs/heads/"} {
		if err := r.Validate(); err == nil {
			t.Errorf("%s accepted", r)
		}
	}
}

func TestHostKeyValidation(t *testing.T) {
	const ed = "AAAAC3NzaC1lZDI1NTE5AAAAIFS/CYhuFo6TY5gPRwRjQ9fMfMyY9LJzZS3RRtUMDGFC"
	if err := (HostKey{Type: HostKeyEd25519, Key: ed}).Validate(); err != nil {
		t.Fatal(err)
	}
	small, err := rsa.GenerateKey(rand.Reader, 1024) //nolint:gosec // deliberately too small
	if err != nil {
		t.Fatal(err)
	}
	pub, err := ssh.NewPublicKey(&small.PublicKey)
	if err != nil {
		t.Fatal(err)
	}
	smallRSA := HostKeyBlob(base64.StdEncoding.EncodeToString(pub.Marshal()))
	bad := map[string]HostKey{
		"type mismatch": {Type: HostKeyECDSA256, Key: ed},
		"unknown type":  {Type: "ssh-dss", Key: ed},
		"not a key":     {Type: HostKeyEd25519, Key: "AAAAAAAAAAAAAAAAAAAAAAAA"},
		"not base64":    {Type: HostKeyEd25519, Key: "!!!!AAAAAAAAAAAAAAAAAAAA"},
		"rsa too small": {Type: HostKeyRSA, Key: smallRSA},
	}
	for name, k := range bad {
		if err := k.Validate(); err == nil {
			t.Errorf("%s accepted", name)
		}
	}
}

func TestSSHBuildInputs(t *testing.T) {
	base := readFixture(t, "task-lease.build-ssh.json")
	lease, err := Decode[TaskLease]([]byte(base))
	if err != nil {
		t.Fatal(err)
	}
	spec, ok := lease.Tasks[0].Spec.(*BuildSpec)
	if !ok || spec.SSHKey == nil || len(spec.Repo.KnownHosts) != 2 || spec.Repo.Ref != "refs/heads/main" {
		t.Fatalf("decoded %+v", lease.Tasks[0].Spec)
	}
	https := readFixture(t, "task-lease.build.json")
	knownHosts := `"knownHosts": [
          {
            "type": "ssh-ed25519",
            "key": "AAAAC3NzaC1lZDI1NTE5AAAAIFS/CYhuFo6TY5gPRwRjQ9fMfMyY9LJzZS3RRtUMDGFC"
          }
        ],`
	bad := map[string]string{
		"ssh without known hosts": strings.Replace(base, `"knownHosts"`, `"knownHostsX"`, 1),
		"ssh with clone token":    strings.Replace(base, `"sshKey"`, `"cloneToken"`, 1),
		"duplicate host key": strings.Replace(base, `"AAAAE2VjZHNhLXNoYTItbmlzdHAyNTYAAAAIbmlzdHAyNTYAAABBBE9is/0eyIdyu5s3WDwYDL2BIqfcv1zYfAEsxk2R8hbZ70L/kijZKhBEZV3WGHUDbNWpmEiMXusuz75mgSEG9Uo="`,
			`"AAAAC3NzaC1lZDI1NTE5AAAAIFS/CYhuFo6TY5gPRwRjQ9fMfMyY9LJzZS3RRtUMDGFC"`, 1),
		"https with ssh key":     strings.Replace(https, `"cloneToken"`, `"sshKey"`, 1),
		"https with known hosts": strings.Replace(https, `"sha": "4015b57a143aec5156fd1444a017a32137a3fd0f"`, `"sha": "4015b57a143aec5156fd1444a017a32137a3fd0f", `+strings.TrimSuffix(knownHosts, ","), 1),
		"bad ref":                strings.Replace(base, `"refs/heads/main"`, `"main"`, 1),
	}
	for name, doc := range bad {
		t.Run(name, func(t *testing.T) {
			if doc == base || doc == https {
				t.Fatal("case did not change the fixture")
			}
			if _, err := Decode[TaskLease]([]byte(doc)); err == nil {
				t.Fatal("accepted")
			}
		})
	}
}
