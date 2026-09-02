package main

import (
	"sync"
	"testing"
)

func TestNext_RoundRobin(t *testing.T) {
	p := NewPool([]string{"a", "b", "c"})

	got := []string{p.Next().Address, p.Next().Address, p.Next().Address, p.Next().Address}
	want := []string{"a", "b", "c", "a"}

	for i := range want {
		if got[i] != want[i] {
			t.Fatalf("call %d: got %s, want %s", i, got[i], want[i])
		}
	}
}

func TestNext_EmptyPool(t *testing.T) {
	p := NewPool(nil)
	if p.Next() != nil {
		t.Fatal("expected nil from an empty pool")
	}
}

func TestReport_TakesProxyOutAfterMaxFailures(t *testing.T) {
	p := NewPool([]string{"a", "b"})

	for i := 0; i < maxFailures; i++ {
		if !p.Report("a") {
			t.Fatalf("Report failed on iteration %d", i)
		}
	}

	// "a" should now be unhealthy, so every call to Next() returns "b".
	for i := 0; i < 4; i++ {
		if got := p.Next().Address; got != "b" {
			t.Fatalf("call %d: got %s, want b (a should be unhealthy)", i, got)
		}
	}
}

func TestReportSuccess_ClearsFailures(t *testing.T) {
	p := NewPool([]string{"a"})

	for i := 0; i < maxFailures; i++ {
		p.Report("a")
	}
	if p.Next() != nil {
		t.Fatal("expected nil: only proxy is unhealthy")
	}

	p.ReportSuccess("a")
	if got := p.Next(); got == nil || got.Address != "a" {
		t.Fatal("expected \"a\" to be healthy again after ReportSuccess")
	}
}

func TestPool_ConcurrentAccess(t *testing.T) {
	p := NewPool([]string{"a", "b", "c"})

	var wg sync.WaitGroup
	for i := 0; i < 100; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			p.Next()
			p.Report("a")
			p.ReportSuccess("a")
			p.List()
		}()
	}
	wg.Wait()
}

func TestAddAndRemove(t *testing.T) {
	p := NewPool(nil)

	p.Add("a")
	if len(p.List()) != 1 {
		t.Fatalf("expected 1 proxy after Add, got %d", len(p.List()))
	}

	// Adding the same address again should not duplicate it.
	p.Add("a")
	if len(p.List()) != 1 {
		t.Fatalf("expected Add to be idempotent, got %d proxies", len(p.List()))
	}

	if !p.Remove("a") {
		t.Fatal("expected Remove to succeed for an existing address")
	}
	if p.Remove("a") {
		t.Fatal("expected Remove to fail for an already-removed address")
	}
}
