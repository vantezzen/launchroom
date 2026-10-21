//go:build linux

package firewall

import (
	"bytes"
	"errors"
	"fmt"
	"net/netip"
	"strings"

	"github.com/google/nftables"
	"github.com/google/nftables/binaryutil"
	"github.com/google/nftables/expr"
	"github.com/google/nftables/userdata"
	"github.com/mdlayher/netlink"
	"golang.org/x/sys/unix"
)

// netlinkReadBuffer is the receive buffer asked for (the kernel caps it at
// net.core.rmem_max).
const netlinkReadBuffer = 8 << 20

// netlinkWriteBuffer is the send buffer: Flush sends the whole table in one
// sendmsg, which the kernel refuses with EMSGSIZE above the socket's send
// buffer (net.core.wmem_max, ~212 KiB by default: about 1,400 rules).
// SO_SNDBUFFORCE (CAP_NET_ADMIN, which programming nftables needs anyway)
// lifts the wmem_max cap; plain SO_SNDBUF is the fallback.
const netlinkWriteBuffer = 8 << 20

// newConn opens an nftables netlink connection whose acknowledgements fit a
// large table: Flush sends the whole table as one batch and the kernel acks
// every message of it. By default each ack echoes its request, so a table of
// a few hundred rules (a server with a dozen environments and mesh
// allowances) overflowed the default receive buffer and the apply failed
// with ENOBUFS. NETLINK_CAP_ACK trims the acks to their headers.
func newConn() (*nftables.Conn, error) {
	return nftables.New(nftables.WithSockOptions(func(c *netlink.Conn) error {
		if err := c.SetOption(netlink.CapAcknowledge, true); err != nil {
			return fmt.Errorf("firewall: netlink cap ack: %w", err)
		}
		if err := c.SetReadBuffer(netlinkReadBuffer); err != nil {
			return fmt.Errorf("firewall: netlink read buffer: %w", err)
		}
		return setWriteBuffer(c, netlinkWriteBuffer)
	}))
}

func setWriteBuffer(c *netlink.Conn, size int) error {
	raw, err := c.SyscallConn()
	if err != nil {
		return fmt.Errorf("firewall: netlink write buffer: %w", err)
	}
	var serr error
	if err := raw.Control(func(fd uintptr) {
		serr = unix.SetsockoptInt(int(fd), unix.SOL_SOCKET, unix.SO_SNDBUFFORCE, size)
	}); err == nil && serr == nil {
		return nil
	}
	if err := c.SetWriteBuffer(size); err != nil {
		return fmt.Errorf("firewall: netlink write buffer: %w", err)
	}
	return nil
}

// ipsDstNAT is the conntrack status bit IPS_DST_NAT.
const ipsDstNAT = 1 << 5

// netlinkBackend programs the kernel through nftables netlink (no nft binary).
type netlinkBackend struct{}

// NewBackend returns the kernel backend.
func NewBackend() Backend { return netlinkBackend{} }

func table() *nftables.Table {
	return &nftables.Table{Name: TableName, Family: nftables.TableFamilyINet}
}

func hookOf(name string) *nftables.ChainHook {
	switch name {
	case "forward":
		return nftables.ChainHookForward
	case "input":
		return nftables.ChainHookInput
	case "prerouting":
		return nftables.ChainHookPrerouting
	}
	return nil
}

// Apply replaces the table atomically: add (so delete cannot fail), delete,
// then add everything, in one transaction.
func (netlinkBackend) Apply(rs Ruleset) error {
	c, err := newConn()
	if err != nil {
		return err
	}
	t := table()
	c.AddTable(t)
	c.DelTable(t)
	t = c.AddTable(table())
	chains := map[string]*nftables.Chain{}
	// Regular chains first: jump rules need their targets.
	for _, regular := range []bool{true, false} {
		for _, ch := range rs.Chains {
			if (ch.Hook == "") != regular {
				continue
			}
			nc := &nftables.Chain{Name: ch.Name, Table: t}
			if ch.Hook != "" {
				accept := nftables.ChainPolicyAccept
				nc.Hooknum, nc.Priority, nc.Type, nc.Policy = hookOf(ch.Hook), nftables.ChainPriorityRef(nftables.ChainPriority(ch.Prio())), nftables.ChainTypeFilter, &accept
			}
			chains[ch.Name] = c.AddChain(nc)
		}
	}
	for _, ch := range rs.Chains {
		for _, r := range ch.Rules {
			exprs, err := ruleExprs(r)
			if err != nil {
				return err
			}
			c.AddRule(&nftables.Rule{Table: t, Chain: chains[ch.Name], Exprs: exprs, UserData: comment(r)})
		}
	}
	if err := c.Flush(); err != nil {
		return fmt.Errorf("firewall: apply nftables table inet %s: %w", TableName, err)
	}
	return nil
}

// Check compares the live table with rs: same chains (hooks, priority) and,
// per chain, the same rules in the same order (by their comment).
func (netlinkBackend) Check(rs Ruleset) ([]string, error) {
	c, err := newConn()
	if err != nil {
		return nil, err
	}
	tables, err := c.ListTablesOfFamily(nftables.TableFamilyINet)
	if err != nil {
		return nil, err
	}
	var t *nftables.Table
	for _, x := range tables {
		if x.Name == TableName {
			t = x
		}
	}
	if t == nil {
		return []string{"table inet " + TableName + " is missing"}, nil
	}
	all, err := c.ListChainsOfTableFamily(nftables.TableFamilyINet)
	if err != nil {
		return nil, err
	}
	live := map[string]*nftables.Chain{}
	for _, ch := range all {
		if ch.Table != nil && ch.Table.Name == TableName {
			live[ch.Name] = ch
		}
	}
	var drift []string
	for _, want := range rs.Chains {
		got, ok := live[want.Name]
		delete(live, want.Name)
		if !ok {
			drift = append(drift, "chain "+want.Name+" is missing")
			continue
		}
		if h := hookOf(want.Hook); h != nil {
			if got.Hooknum == nil || *got.Hooknum != *h || got.Priority == nil || *got.Priority != nftables.ChainPriority(want.Prio()) ||
				got.Policy == nil || *got.Policy != nftables.ChainPolicyAccept {
				drift = append(drift, "chain "+want.Name+" has a different hook, priority or policy")
				continue
			}
		} else if got.Hooknum != nil {
			drift = append(drift, "chain "+want.Name+" became a base chain")
			continue
		}
		rules, err := c.GetRules(t, got)
		if err != nil {
			return nil, err
		}
		if len(rules) != len(want.Rules) {
			drift = append(drift, fmt.Sprintf("chain %s has %d rules, want %d", want.Name, len(rules), len(want.Rules)))
			continue
		}
		for i, r := range rules {
			if !bytes.Equal(r.UserData, comment(want.Rules[i])) {
				drift = append(drift, fmt.Sprintf("chain %s rule %d changed", want.Name, i))
				break
			}
		}
	}
	for name := range live {
		drift = append(drift, "unexpected chain "+name)
	}
	return drift, nil
}

// Remove deletes the table (no error when it does not exist).
func (netlinkBackend) Remove() error {
	c, err := newConn()
	if err != nil {
		return err
	}
	t := table()
	c.AddTable(t)
	c.DelTable(t)
	return c.Flush()
}

func comment(r Rule) []byte {
	s := r.String()
	if len(s) > 250 {
		s = s[:250]
	}
	return userdata.AppendString(nil, userdata.TypeComment, s)
}

func ifname(name string) []byte {
	b := make([]byte, unix.IFNAMSIZ)
	copy(b, name)
	return b
}

// ruleExprs translates a Rule into nftables expressions (what `nft` would
// generate for the rule's String form).
func ruleExprs(r Rule) ([]expr.Any, error) {
	var e []expr.Any
	if r.IIF != "" {
		if len(r.IIF) >= unix.IFNAMSIZ {
			return nil, fmt.Errorf("firewall: interface name %q is too long", r.IIF)
		}
		e = append(e, &expr.Meta{Key: expr.MetaKeyIIFNAME, Register: 1}, &expr.Cmp{Op: expr.CmpOpEq, Register: 1, Data: ifname(r.IIF)})
	}
	for _, n := range r.IIFNot {
		if n == "" || len(n) >= unix.IFNAMSIZ {
			return nil, fmt.Errorf("firewall: interface name %q is invalid", n)
		}
		e = append(e, &expr.Meta{Key: expr.MetaKeyIIFNAME, Register: 1}, &expr.Cmp{Op: expr.CmpOpNeq, Register: 1, Data: ifname(n)})
	}
	if r.OIF != "" {
		if len(r.OIF) >= unix.IFNAMSIZ {
			return nil, fmt.Errorf("firewall: interface name %q is too long", r.OIF)
		}
		e = append(e, &expr.Meta{Key: expr.MetaKeyOIFNAME, Register: 1}, &expr.Cmp{Op: expr.CmpOpEq, Register: 1, Data: ifname(r.OIF)})
	}
	if r.OIFPrefix != "" {
		e = append(e, &expr.Meta{Key: expr.MetaKeyOIFNAME, Register: 1}, &expr.Cmp{Op: expr.CmpOpEq, Register: 1, Data: []byte(r.OIFPrefix)})
	}
	if r.Established {
		e = append(e,
			&expr.Ct{Register: 1, Key: expr.CtKeySTATE},
			&expr.Bitwise{SourceRegister: 1, DestRegister: 1, Len: 4,
				Mask: binaryutil.NativeEndian.PutUint32(expr.CtStateBitESTABLISHED | expr.CtStateBitRELATED),
				Xor:  binaryutil.NativeEndian.PutUint32(0)},
			&expr.Cmp{Op: expr.CmpOpNeq, Register: 1, Data: []byte{0, 0, 0, 0}})
	}
	if r.DNAT {
		e = append(e,
			&expr.Ct{Register: 1, Key: expr.CtKeySTATUS},
			&expr.Bitwise{SourceRegister: 1, DestRegister: 1, Len: 4,
				Mask: binaryutil.NativeEndian.PutUint32(ipsDstNAT),
				Xor:  binaryutil.NativeEndian.PutUint32(0)},
			&expr.Cmp{Op: expr.CmpOpNeq, Register: 1, Data: []byte{0, 0, 0, 0}})
	}
	if r.Src.IsValid() {
		e = append(e, addrExprs(r.Src, 12, 8)...)
	}
	if r.Dst.IsValid() {
		e = append(e, addrExprs(r.Dst, 16, 24)...)
	}
	if r.Proto != "" {
		var proto byte
		switch r.Proto {
		case "tcp":
			proto = unix.IPPROTO_TCP
		case "udp":
			proto = unix.IPPROTO_UDP
		default:
			return nil, fmt.Errorf("firewall: unknown protocol %q", r.Proto)
		}
		e = append(e, &expr.Meta{Key: expr.MetaKeyL4PROTO, Register: 1}, &expr.Cmp{Op: expr.CmpOpEq, Register: 1, Data: []byte{proto}})
		if r.DPort != 0 {
			e = append(e,
				&expr.Payload{DestRegister: 1, Base: expr.PayloadBaseTransportHeader, Offset: 2, Len: 2},
				&expr.Cmp{Op: expr.CmpOpEq, Register: 1, Data: binaryutil.BigEndian.PutUint16(r.DPort)})
		}
	}
	v, err := verdict(r.Verdict)
	if err != nil {
		return nil, err
	}
	return append(e, v), nil
}

func nfproto(a netip.Addr) []expr.Any {
	proto := byte(unix.NFPROTO_IPV4)
	if a.Is6() {
		proto = unix.NFPROTO_IPV6
	}
	return []expr.Any{
		&expr.Meta{Key: expr.MetaKeyNFPROTO, Register: 1},
		&expr.Cmp{Op: expr.CmpOpEq, Register: 1, Data: []byte{proto}},
	}
}

// addrExprs matches the source (offsets 12/8) or destination (16/24)
// address of the IPv4/IPv6 header against p.
func addrExprs(p netip.Prefix, off4, off6 uint32) []expr.Any {
	p = p.Masked()
	off, n := off4, uint32(4)
	if p.Addr().Is6() {
		off, n = off6, 16
	}
	e := append(nfproto(p.Addr()),
		&expr.Payload{DestRegister: 1, Base: expr.PayloadBaseNetworkHeader, Offset: off, Len: n},
	)
	if p.Bits() < p.Addr().BitLen() {
		mask := make([]byte, n)
		for i := range p.Bits() {
			mask[i/8] |= 0x80 >> (i % 8)
		}
		e = append(e, &expr.Bitwise{SourceRegister: 1, DestRegister: 1, Len: n, Mask: mask, Xor: make([]byte, n)})
	}
	return append(e, &expr.Cmp{Op: expr.CmpOpEq, Register: 1, Data: p.Addr().AsSlice()})
}

func verdict(v string) (expr.Any, error) {
	switch {
	case v == "accept":
		return &expr.Verdict{Kind: expr.VerdictAccept}, nil
	case v == "drop":
		return &expr.Verdict{Kind: expr.VerdictDrop}, nil
	case strings.HasPrefix(v, "jump "):
		return &expr.Verdict{Kind: expr.VerdictJump, Chain: strings.TrimPrefix(v, "jump ")}, nil
	}
	return nil, errors.New("firewall: unknown verdict " + v)
}
