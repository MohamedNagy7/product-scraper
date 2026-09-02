package main

import (
	"context"
	"log"
	"net/http"
	"os"
	"strings"
	"time"
)

const revalidateInterval = 15 * time.Minute
const bootstrapTimeout = 3 * time.Minute

func main() {
	seed := loadSeedProxies()

	pool := NewPool(nil)

	go bootstrapPool(pool, seed)
	go runPeriodicRevalidation(pool)

	mux := http.NewServeMux()
	mux.HandleFunc("GET /proxy", pool.handleNext)
	mux.HandleFunc("GET /proxies", pool.handleList)
	mux.HandleFunc("POST /proxies", pool.handleAdd)
	mux.HandleFunc("POST /proxies/remove", pool.handleRemove)
	mux.HandleFunc("POST /proxies/report", pool.handleReport)
	mux.HandleFunc("POST /proxies/report-success", pool.handleReportSuccess)

	addr := os.Getenv("PORT")
	if addr == "" {
		addr = "8081"
	}
	addr = ":" + strings.TrimPrefix(addr, ":")

	log.Printf("proxy-manager listening on %s", addr)
	log.Fatal(http.ListenAndServe(addr, mux))
}

func bootstrapPool(pool *Pool, seed []string) {
	log.Printf("verifying %d seed proxies...", len(seed))

	ctx, cancel := context.WithTimeout(context.Background(), bootstrapTimeout)
	defer cancel()

	var healthy, failed int
	for r := range VerifyProxies(ctx, seed) {
		if r.OK {
			pool.Add(r.Address)
			healthy++
		} else {
			failed++
		}
	}
	log.Printf("initial verification done: %d/%d proxies healthy", healthy, healthy+failed)
}

// runPeriodicRevalidation re-checks every proxy currently in the pool on a
// fixed interval, so proxies that die (or turn out to be MITM-ing traffic)
// after passing the initial sweep get demoted without waiting for
// maxFailures worth of real, failed scrapes.
func runPeriodicRevalidation(pool *Pool) {
	ticker := time.NewTicker(revalidateInterval)
	defer ticker.Stop()

	for range ticker.C {
		current := pool.List()
		addrs := make([]string, len(current))
		for i, p := range current {
			addrs[i] = p.Address
		}

		ctx, cancel := context.WithTimeout(context.Background(), bootstrapTimeout)
		var healthy, failed int
		for r := range VerifyProxies(ctx, addrs) {
			pool.SetVerified(r.Address, r.OK)
			if r.OK {
				healthy++
			} else {
				failed++
			}
		}
		cancel()
		log.Printf("revalidation done: %d/%d proxies still healthy", healthy, healthy+failed)
	}
}

func loadSeedProxies() []string {
	var out []string

	file, err := os.ReadFile("free-proxy-list.txt")
	if err != nil {
		log.Println("Error reading file:", err)
		return nil
	}
	for _, line := range strings.Split(string(file), "\n") {
		line = strings.TrimSpace(line)
		if line != "" {
			out = append(out, line)
		}
	}
	return out
}
