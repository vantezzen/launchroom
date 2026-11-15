package controlplane

import (
	"testing"
	"time"
)

func TestJitterBounds(t *testing.T) {
	for range 1000 {
		d := Jitter(10 * time.Second)
		if d < 9*time.Second || d > 11*time.Second {
			t.Fatalf("jitter %v out of bounds", d)
		}
	}
}
