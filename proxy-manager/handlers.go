package main

import (
	"encoding/json"
	"net/http"
)

func writeJSON(w http.ResponseWriter, status int, v any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(v)
}

type addressRequest struct {
	Address string `json:"address"`
}

func (p *Pool) handleNext(w http.ResponseWriter, r *http.Request) {
	proxy := p.Next()
	if proxy == nil {
		writeJSON(w, http.StatusServiceUnavailable, map[string]string{
			"error": "no healthy proxies available",
		})
		return
	}
	writeJSON(w, http.StatusOK, proxy)
}

func (p *Pool) handleList(w http.ResponseWriter, r *http.Request) {
	writeJSON(w, http.StatusOK, p.List())
}

func (p *Pool) handleAdd(w http.ResponseWriter, r *http.Request) {
	req, ok := decodeAddress(w, r)
	if !ok {
		return
	}
	writeJSON(w, http.StatusCreated, p.Add(req.Address))
}

func (p *Pool) handleRemove(w http.ResponseWriter, r *http.Request) {
	req, ok := decodeAddress(w, r)
	if !ok {
		return
	}
	if !p.Remove(req.Address) {
		writeJSON(w, http.StatusNotFound, map[string]string{"error": "proxy not found"})
		return
	}
	w.WriteHeader(http.StatusNoContent)
}

func (p *Pool) handleReport(w http.ResponseWriter, r *http.Request) {
	req, ok := decodeAddress(w, r)
	if !ok {
		return
	}
	if !p.Report(req.Address) {
		writeJSON(w, http.StatusNotFound, map[string]string{"error": "proxy not found"})
		return
	}
	w.WriteHeader(http.StatusNoContent)
}

func (p *Pool) handleReportSuccess(w http.ResponseWriter, r *http.Request) {
	req, ok := decodeAddress(w, r)
	if !ok {
		return
	}
	if !p.ReportSuccess(req.Address) {
		writeJSON(w, http.StatusNotFound, map[string]string{"error": "proxy not found"})
		return
	}
	w.WriteHeader(http.StatusNoContent)
}

func decodeAddress(w http.ResponseWriter, r *http.Request) (addressRequest, bool) {
	var req addressRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil || req.Address == "" {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "address is required"})
		return req, false
	}
	return req, true
}
