package main

import (
	"sync"
	"time"
)

const maxFailures = 3

type Proxy struct {
	Address  string    `json:"address"`
	Healthy  bool      `json:"healthy"`
	Failures int       `json:"failures"`
	LastUsed time.Time `json:"last_used,omitempty"`
}

// Pool is the in-memory proxy registry. All access goes through the mutex,
// including reads of `next`.
type Pool struct {
	mu      sync.RWMutex
	proxies []*Proxy
	next    int
}

func NewPool(seed []string) *Pool {
	p := &Pool{}
	for _, addr := range seed {
		p.proxies = append(p.proxies, &Proxy{Address: addr, Healthy: true})
	}
	return p
}

// Next returns the next healthy proxy in round-robin order
func (p *Pool) Next() *Proxy {
	p.mu.Lock()
	defer p.mu.Unlock()

	n := len(p.proxies)
	if n == 0 {
		return nil
	}

	for i := 0; i < n; i++ {
		idx := (p.next + i) % n
		proxy := p.proxies[idx]

		if proxy.Healthy {
			p.next = (idx + 1) % n
			proxy.LastUsed = time.Now()
			return proxy
		}
	}

	return nil
}

func (p *Pool) List() []*Proxy {
	p.mu.RLock()
	defer p.mu.RUnlock()

	out := make([]*Proxy, len(p.proxies))
	copy(out, p.proxies)
	return out
}

func (p *Pool) Add(address string) *Proxy {
	p.mu.Lock()
	defer p.mu.Unlock()

	for _, existing := range p.proxies {
		if existing.Address == address {
			return existing
		}
	}

	proxy := &Proxy{Address: address, Healthy: true}
	p.proxies = append(p.proxies, proxy)
	return proxy
}

func (p *Pool) Remove(address string) bool {
	p.mu.Lock()
	defer p.mu.Unlock()

	for i, proxy := range p.proxies {
		if proxy.Address == address {
			p.proxies = append(p.proxies[:i], p.proxies[i+1:]...)
			return true
		}
	}
	return false
}

// Report marks a failed request against a proxy. Once it hits maxFailures
// consecutive failures, it's taken out of Next()'s rotation.
func (p *Pool) Report(address string) bool {
	p.mu.Lock()
	defer p.mu.Unlock()

	for _, proxy := range p.proxies {
		if proxy.Address == address {
			proxy.Failures++
			if proxy.Failures >= maxFailures {
				proxy.Healthy = false
			}
			return true
		}
	}
	return false
}

func (p *Pool) ReportSuccess(address string) bool {
	p.mu.Lock()
	defer p.mu.Unlock()

	for _, proxy := range p.proxies {
		if proxy.Address == address {
			proxy.Failures = 0
			proxy.Healthy = true
			return true
		}
	}
	return false
}
