// failure-lab: a tiny web app that fails on purpose, for the runtime
// visibility and self-recovery tests (dev/apps-e2e/cases/failure-lab.sh).
//
// The mode comes from FIXTURE_MODE or, when that is unset, from mode.txt
// (the branches of this fixture add one):
//
//	(none)        serves "ok" on $PORT (default 8080)
//	crash-late    serves, then after CRASH_AFTER (default 20s) writes a
//	              60-line trace to stderr and exits 3
//	oom           serves, then allocates and touches 16 MiB every 100 ms
//	              until the kernel kills it
//	hang          serves, then after HANG_AFTER (default 20s) stops answering
//	              (handlers block; the process stays up)
//	crash-after-60s  like crash-late with CRASH_AFTER=60s (branch v2-crash)
package main

import (
	"fmt"
	"log"
	"net/http"
	"os"
	"strings"
	"sync/atomic"
	"time"
)

func mode() string {
	if m := os.Getenv("FIXTURE_MODE"); m != "" {
		return m
	}
	b, err := os.ReadFile("mode.txt")
	if err != nil {
		return ""
	}
	return strings.TrimSpace(string(b))
}

func after(name string, def time.Duration) time.Duration {
	if d, err := time.ParseDuration(os.Getenv(name)); err == nil {
		return d
	}
	return def
}

func main() {
	m := mode()
	port := os.Getenv("PORT")
	if port == "" {
		port = "8080"
	}
	var hung atomic.Bool
	http.HandleFunc("/", func(w http.ResponseWriter, r *http.Request) {
		if hung.Load() {
			select {}
		}
		fmt.Fprintf(w, "ok mode=%q\n", m)
	})
	log.Printf("failure-lab listening on :%s, mode %q", port, m)

	switch m {
	case "crash-late", "crash-after-60s":
		d := after("CRASH_AFTER", 20*time.Second)
		if m == "crash-after-60s" {
			d = 60 * time.Second
		}
		time.AfterFunc(d, func() {
			for i := 1; i <= 60; i++ {
				fmt.Fprintf(os.Stderr, "trace %02d: failure-lab crashed on purpose in frame %d\n", i, i)
			}
			os.Exit(3)
		})
	case "oom":
		go func() {
			var hold [][]byte
			for {
				b := make([]byte, 16<<20)
				for i := range b {
					b[i] = 1
				}
				hold = append(hold, b)
				log.Printf("holding %d MiB", len(hold)*16)
				time.Sleep(100 * time.Millisecond)
			}
		}()
	case "hang":
		time.AfterFunc(after("HANG_AFTER", 20*time.Second), func() {
			log.Print("not answering any more")
			hung.Store(true)
		})
	}

	srv := &http.Server{Addr: ":" + port, ReadHeaderTimeout: 10 * time.Second}
	log.Fatal(srv.ListenAndServe())
}
