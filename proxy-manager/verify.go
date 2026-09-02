package main

import (
	"context"
	"net/http"
	"net/url"
	"sync"
	"time"
)

const checkTarget = "https://www.google.com/generate_204"

const (
	checkTimeout     = 8 * time.Second
	checkConcurrency = 50
)

type checkResult struct {
	Address string
	OK      bool
	Elapsed time.Duration
	Err     error
}

// To prevent MITM ATTACKS, and dropped proxies
func verifyProxy(ctx context.Context, address string) checkResult {
	start := time.Now()

	proxyURL, err := url.Parse("http://" + address)
	if err != nil {
		return checkResult{Address: address, OK: false, Err: err}
	}

	client := &http.Client{
		Timeout: checkTimeout,
		Transport: &http.Transport{
			Proxy: http.ProxyURL(proxyURL),
		},
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodGet, checkTarget, nil)
	if err != nil {
		return checkResult{Address: address, OK: false, Err: err}
	}

	resp, err := client.Do(req)
	if err != nil {
		return checkResult{Address: address, OK: false, Elapsed: time.Since(start), Err: err}
	}
	defer resp.Body.Close()

	ok := resp.StatusCode >= 200 && resp.StatusCode < 400
	return checkResult{Address: address, OK: ok, Elapsed: time.Since(start)}
}

func VerifyProxies(ctx context.Context, addresses []string) <-chan checkResult {
	jobs := make(chan string)
	results := make(chan checkResult)

	workers := checkConcurrency
	if len(addresses) > 0 && workers > len(addresses) {
		workers = len(addresses)
	}

	var wg sync.WaitGroup
	for i := 0; i < workers; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			for addr := range jobs {
				select {
				case results <- verifyProxy(ctx, addr):
				case <-ctx.Done():
					return
				}
			}
		}()
	}

	go func() {
		defer close(jobs)
		for _, addr := range addresses {
			select {
			case jobs <- addr:
			case <-ctx.Done():
				return
			}
		}
	}()

	go func() {
		wg.Wait()
		close(results)
	}()

	return results
}
