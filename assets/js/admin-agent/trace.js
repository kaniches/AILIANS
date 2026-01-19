/* global APAI_AGENT_DATA */

// ============================================================
// AutoProduct AI — Trace Store (UI-only)
//
// @UI_ONLY
// - Keeps trace IDs for the current tab/session.
// - Fetches filtered excerpts for those trace IDs.
// - Used by the "Copiar" button to include tracer for this conversation.
//
// Design goals:
// - Deterministic + small (no heavy log reads in the browser)
// - Safe: excerpt is server-filtered by trace_id.
// ============================================================

(function(){
    'use strict';

    // NOTE: We intentionally do NOT persist trace IDs (no sessionStorage / localStorage).
    // We only want the trace of *this* chat session (the current page lifetime).
    // This avoids dragging traces from previous chats after refresh.
    const MAX_IDS = 1200; // generous; backend batches at 200 per request

    const state = {
        // Stored as newest-first for cheap unshift()
        ids: [],
        // Cache: key -> excerpt text
        excerpts: {},
    };

    function reset(){
        state.ids = [];
        state.excerpts = {};
    }

    function add(traceId){
        const tid = (traceId || '').toString().trim();
        if(!tid) return;
        if(state.ids.indexOf(tid) !== -1) return;
        state.ids.unshift(tid);
        if(state.ids.length > MAX_IDS) state.ids = state.ids.slice(0, MAX_IDS);
    }

    function getIds(opts){
        // Chronological (oldest -> newest)
        const chron = state.ids.slice().reverse();
        // Backward compatible: if no args, return all.
        if(opts && typeof opts === 'object'){
            const limit = parseInt(opts.limit, 10);
            if(Number.isFinite(limit) && limit > 0){
                return chron.slice(-limit);
            }
        }
        return chron;
    }

    function chunkArray(arr, size){
        const out = [];
        for(let i = 0; i < arr.length; i += size){
            out.push(arr.slice(i, i + size));
        }
        return out;
    }

    // Fetch a combined excerpt for a set of trace IDs.
    // IMPORTANT: Use a single POST call (avoids spamming many requests on "Copiar").
    async function fetchCombinedExcerpt(traceIds, maxLines){
        if(!APAI_AGENT_DATA || !APAI_AGENT_DATA.trace_excerpt_url) return '';

        const ids = (Array.isArray(traceIds) ? traceIds : []).filter(Boolean);
        if(!ids.length) return '';

        const ml = (typeof maxLines === 'number' && maxLines > 0) ? maxLines : 250;
        const cacheKey = ids.join('|') + '::' + ml;
        if(state.excerpts[cacheKey]) return state.excerpts[cacheKey];

        try{
            const res = await fetch(APAI_AGENT_DATA.trace_excerpt_url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': APAI_AGENT_DATA.nonce,
                },
                body: JSON.stringify({ trace_ids: ids, max_lines: ml }),
            });
            const data = await res.json();
            const meta = (data && data.meta) ? data.meta : null;
            const raw = (data && data.ok && data.excerpt) ? String(data.excerpt) : '';
            let txt = raw;
            if(meta){
                // F6.OBS: include lightweight meta header for humans (copy/debug), not sent to the model.
                const head = 'TRACER META: ' + JSON.stringify({file_size: meta.file_size || null, bytes_read: meta.bytes_read || null, truncated: !!meta.truncated, lines_found: meta.lines_found || null, mode: meta.mode || null});
                txt = head + "\n" + raw;
            }
            state.excerpts[cacheKey] = txt;
            return txt;
        }catch(e){
            // Do NOT cache failures (user may want to retry after enabling trace).
            return '';
        }
    }

    // The REST endpoint caps trace_ids to 200, so for long chats we batch requests.
    async function fetchCombinedExcerptChunked(traceIds, maxLines){
        const ids = (Array.isArray(traceIds) ? traceIds : []).filter(Boolean);
        if(!ids.length) return '';

        const chunks = chunkArray(ids, 200);
        const parts = [];

        for(let i = 0; i < chunks.length; i++){
            const txt = await fetchCombinedExcerpt(chunks[i], maxLines);
            if(txt) parts.push(String(txt).trim());
        }

        return parts.join('\n');
    }

    async function getCombinedTracerText(opts){
        const options = opts || {};
        const ids = getIds();
        if(!ids.length) return '';

        // Default: include the whole *current* chat session.
        const maxTraces = (typeof options.maxTraces === 'number' && options.maxTraces > 0) ? options.maxTraces : ids.length;
        const maxLines  = (typeof options.maxLines === 'number' && options.maxLines > 0) ? options.maxLines : 1000;

        // Keep the most recent traces, but preserve chronological order
        const limited = ids.slice(-maxTraces);
        const txt = await fetchCombinedExcerptChunked(limited, maxLines);
        return txt ? txt.trim() : '';
    }

    // Public API
    window.APAI_Trace = {
        add,
        reset,
        getIds,
        getCombinedTracerText,
        // Back-compat alias used by copy.js
        getCombinedText: getCombinedTracerText,
    };
})();
