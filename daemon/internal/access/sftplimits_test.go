package access

import (
	"bytes"
	"crypto/rand"
	"encoding/binary"
	"io"
	"os"
	"path/filepath"
	"testing"

	"github.com/pkg/sftp"
	"golang.org/x/crypto/ssh"
)

func sftpPacket(typ byte, body ...[]byte) []byte {
	b := []byte{0, 0, 0, 0, typ}
	for _, x := range body {
		b = append(b, x...)
	}
	binary.BigEndian.PutUint32(b, uint32(len(b)-4)) //nolint:gosec // test packets are small
	return b
}

func sftpString(s string) []byte {
	return append(binary.BigEndian.AppendUint32(nil, uint32(len(s))), s...) //nolint:gosec // test strings are small
}

func readSFTPPacket(t *testing.T, r io.Reader) (byte, []byte) {
	t.Helper()
	var l [4]byte
	if _, err := io.ReadFull(r, l[:]); err != nil {
		t.Fatal(err)
	}
	b := make([]byte, binary.BigEndian.Uint32(l[:]))
	if _, err := io.ReadFull(r, b); err != nil {
		t.Fatal(err)
	}
	return b[0], b[1:]
}

// The SSH front end advertises limits@openssh.com and answers it with
// 255 KiB reads and writes, like OpenSSH's sftp-server; clients that use
// those sizes upload and download through it.
func TestSFTPLimitsExtension(t *testing.T) {
	addr, _, c, host := sshServer(t, SSHOptions{Password: "correct-horse-battery-staple-0123"}, "127.0.0.1/32")
	cl, err := dialSSH(addr, host, ssh.Password("correct-horse-battery-staple-0123"))
	if err != nil {
		t.Fatal(err)
	}
	defer func() { _ = cl.Close() }()

	s, err := cl.NewSession()
	if err != nil {
		t.Fatal(err)
	}
	in, _ := s.StdinPipe()
	out, _ := s.StdoutPipe()
	if err := s.RequestSubsystem("sftp"); err != nil {
		t.Fatal(err)
	}
	_, _ = in.Write(sftpPacket(1, binary.BigEndian.AppendUint32(nil, 3)))
	typ, body := readSFTPPacket(t, out)
	if typ != fxpVersion || !bytes.Contains(body, sftpString(limitsExtension)) || !bytes.Contains(body, sftpString("posix-rename@openssh.com")) {
		t.Fatalf("version %d %q", typ, body)
	}
	// Split across writes, like a client may send it.
	req := sftpPacket(fxpExtended, binary.BigEndian.AppendUint32(nil, 7), sftpString(limitsExtension))
	_, _ = in.Write(req[:3])
	_, _ = in.Write(req[3:])
	typ, body = readSFTPPacket(t, out)
	if typ != fxpExtendedReply || len(body) != 36 {
		t.Fatalf("limits reply %d %x", typ, body)
	}
	if id, pkt, rd, wr := binary.BigEndian.Uint32(body), binary.BigEndian.Uint64(body[4:]), binary.BigEndian.Uint64(body[12:]), binary.BigEndian.Uint64(body[20:]); id != 7 || pkt != 256<<10 || rd != 261120 || wr != 261120 {
		t.Fatalf("limits %d %d %d %d", id, pkt, rd, wr)
	}
	// Other extended requests still reach the request server.
	_, _ = in.Write(sftpPacket(fxpExtended, binary.BigEndian.AppendUint32(nil, 8), sftpString("nope@example.com")))
	if typ, body = readSFTPPacket(t, out); typ != 101 || binary.BigEndian.Uint32(body) != 8 {
		t.Fatalf("unknown extension: %d %x", typ, body)
	}
	_ = s.Close()

	sc, err := sftp.NewClient(cl, sftp.MaxPacketUnchecked(sftpMaxData), sftp.UseConcurrentWrites(true))
	if err != nil {
		t.Fatal(err)
	}
	defer func() { _ = sc.Close() }()
	data := make([]byte, 3<<20+123)
	_, _ = rand.Read(data)
	f, err := sc.Create("/big.bin")
	if err != nil {
		t.Fatal(err)
	}
	if _, err := f.ReadFrom(bytes.NewReader(data)); err != nil {
		t.Fatal(err)
	}
	if err := f.Close(); err != nil {
		t.Fatal(err)
	}
	if got, _ := os.ReadFile(filepath.Join(c.root, "big.bin")); !bytes.Equal(got, data) {
		t.Fatalf("uploaded %d bytes, want %d", len(got), len(data))
	}
	r, err := sc.Open("/big.bin")
	if err != nil {
		t.Fatal(err)
	}
	got, err := io.ReadAll(r)
	if err != nil || !bytes.Equal(got, data) {
		t.Fatalf("downloaded %d bytes: %v", len(got), err)
	}
}

type memConn struct {
	in  io.Reader
	out bytes.Buffer
}

func (m *memConn) Read(p []byte) (int, error)  { return m.in.Read(p) }
func (m *memConn) Write(p []byte) (int, error) { return m.out.Write(p) }
func (*memConn) Close() error                  { return nil }

// A limits reply waits for the packet the request server is writing.
func TestLimitsConnRepliesBetweenPackets(t *testing.T) {
	req := sftpPacket(fxpExtended, binary.BigEndian.AppendUint32(nil, 9), sftpString(limitsExtension))
	init := sftpPacket(1, binary.BigEndian.AppendUint32(nil, 3))
	bc := &memConn{in: bytes.NewReader(append(append([]byte(nil), init...), req...))}
	c := newLimitsConn(bc)

	version := sftpPacket(fxpVersion, binary.BigEndian.AppendUint32(nil, 3))
	_, _ = c.Write(version[:2])
	_, _ = c.Write(version[2:])
	got := make([]byte, len(init))
	if _, err := io.ReadFull(c, got); err != nil || !bytes.Equal(got, init) {
		t.Fatalf("init %x %v", got, err)
	}
	data := sftpPacket(103, binary.BigEndian.AppendUint32(nil, 5), sftpString("payload"))
	_, _ = c.Write(data[:6]) // the header of a packet; then the request arrives
	done := make(chan struct{})
	go func() {
		defer close(done)
		_, _ = c.Read(make([]byte, 16)) // answers the request, then reads EOF
	}()
	<-done
	_, _ = c.Write(data[6:])

	typ, body := readSFTPPacket(t, &bc.out)
	if typ != fxpVersion || !bytes.HasSuffix(body, append(sftpString(limitsExtension), sftpString("1")...)) {
		t.Fatalf("version %d %x", typ, body)
	}
	if typ, _ = readSFTPPacket(t, &bc.out); typ != 103 {
		t.Fatalf("data packet split: %d", typ)
	}
	if typ, body = readSFTPPacket(t, &bc.out); typ != fxpExtendedReply || binary.BigEndian.Uint32(body) != 9 {
		t.Fatalf("reply %d %x", typ, body)
	}
}

// An open without SSH_FXF_TRUNC (a resumed upload) doesn't empty the file.
func TestSFTPWriteWithoutTruncKeepsTheFile(t *testing.T) {
	box := newToolBox(t)
	c := sftpPair(t, box)
	if err := os.WriteFile(filepath.Join(box.root, "part.bin"), []byte("0123456789"), 0o600); err != nil {
		t.Fatal(err)
	}
	f, err := c.OpenFile("/part.bin", os.O_WRONLY)
	if err != nil {
		t.Fatal(err)
	}
	if _, err := f.Write([]byte("ab")); err != nil {
		t.Fatal(err)
	}
	if err := f.Close(); err != nil {
		t.Fatal(err)
	}
	if got, _ := os.ReadFile(filepath.Join(box.root, "part.bin")); string(got) != "ab23456789" {
		t.Fatalf("got %q", got)
	}
}
