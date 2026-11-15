package main

import (
	"context"
	"log/slog"
	"net"
)

// meshListener is one listener launchroomd serves on its mesh address (the
// registry and certificate storage forwarders, the link probe server). set
// moves or stops it when the wanted address changes.
type meshListener struct {
	name string
	addr string
	stop context.CancelFunc
}

// set serves addr with serve ("" stops it). A failed listen is retried on
// the next call.
func (m *meshListener) set(ctx context.Context, log *slog.Logger, addr string, serve func(context.Context, net.Listener) error) {
	if addr == m.addr {
		return
	}
	if m.stop != nil {
		m.stop()
		log.Info(m.name+" stopped", "addr", m.addr)
	}
	m.addr, m.stop = "", nil
	if addr == "" {
		return
	}
	var lc net.ListenConfig
	l, err := lc.Listen(ctx, "tcp", addr)
	if err != nil {
		log.Warn(m.name+" cannot listen; retrying on the next pass", "addr", addr, "err", err)
		return
	}
	lctx, cancel := context.WithCancel(ctx)
	m.addr, m.stop = addr, cancel
	log.Info(m.name+" listening", "addr", addr)
	go func() {
		if err := serve(lctx, l); err != nil {
			log.Error(m.name+" stopped", "err", err)
		}
	}()
}
