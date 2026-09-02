package main

import (
	"log"
	"net/http"
	"os"
	"strings"
)

func main() {
	pool := NewPool(loadSeedProxies())

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
