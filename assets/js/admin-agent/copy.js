/* global APAI_AGENT_DATA */

// ============================================================
// AutoProduct AI — Copy Conversation (UI-only)
//
// @UI_ONLY
// Copies:
// - Full chat transcript (DOM order)
// - Pending action cards
// - Selector cards
// - Debug FULL block (if present)
// - Tracer excerpt for THIS conversation (trace IDs captured in JS)
//
// ============================================================

(function(){
    'use strict';

    function normalizeBreaks(str){
        return String(str || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n');
    }

    function safeInnerText(el){
        if(!el) return '';
        // innerText keeps line breaks from <br> and block elements.
        const t = (typeof el.innerText === 'string') ? el.innerText : el.textContent;
        return normalizeBreaks(t).trim();
    }

    function extractMessageBlock(node){
        const isUser = node.classList.contains('apai-user');
        const role = isUser ? 'USUARIO' : 'AGENTE';

        // The bubble contains the rendered content.
        const bubble = node.querySelector('.apai-agent-bubble') || node;
        const text = safeInnerText(bubble);
        if(!text) return '';
        return role + ': ' + text;
    }

    function extractActionCardBlock(card){
        // Two flavors:
        // - Pending/action proposal: .apai-agent-action-card
        // - Target selection card:   .apai-agent-action-card.apai-agent-target-card
        const isTarget = card.classList.contains('apai-agent-target-card');
        const lines = [];

        if(isTarget){
            lines.push('CARD: SELECTOR DE PRODUCTO');

            const title = safeInnerText(card.querySelector('.apai-target-head-title'));
            const sub = safeInnerText(card.querySelector('.apai-target-head-sub'));
            if(title) lines.push(title);
            if(sub) lines.push(sub);

            const rows = Array.from(card.querySelectorAll('.apai-target-row'));
            if(rows.length){
                lines.push('Candidatos (vista actual):');
                rows.forEach((row) => {
                    const idTxt = safeInnerText(row.querySelector('.apai-target-id'));
                    const titleTxt = safeInnerText(row.querySelector('.apai-target-title'));
                    const metaTxt = safeInnerText(row.querySelector('.apai-target-meta'));
                    const parts = [];
                    if(idTxt) parts.push(idTxt);
                    if(titleTxt) parts.push(titleTxt);
                    const s = '• ' + parts.join(' — ') + (metaTxt ? (' (' + metaTxt + ')') : '');
                    lines.push(s);
                });
            }

            return lines.join('\n');
        }

        lines.push('CARD: ACCIÓN PROPUESTA');
        const heading = safeInnerText(card.querySelector('.apai-agent-action-title'));
        const summary = safeInnerText(card.querySelector('.apai-agent-action-summary'));
        if(heading) lines.push(heading);
        if(summary) lines.push(summary);

        // Product preview (optional)
        const name = safeInnerText(card.querySelector('.apai-action-name'));
        const sub = safeInnerText(card.querySelector('.apai-action-sub'));
        const catChips = Array.from(card.querySelectorAll('.apai-action-cats .apai-chip')).map(c => safeInnerText(c)).filter(Boolean);
        if(name || sub || catChips.length){
            lines.push('Producto (preview):');
            if(name) lines.push('- ' + name);
            if(sub) lines.push('- ' + sub);
            if(catChips.length) lines.push('- Categorías: ' + catChips.join(', '));
        }

        // Variation preview table (optional)
        const varTitle = safeInnerText(card.querySelector('.apai-agent-variation-preview-title'));
        if(varTitle){
            lines.push(varTitle + ':');
            const rows = Array.from(card.querySelectorAll('.apai-agent-variation-preview tbody tr'));
            rows.slice(0, 10).forEach((tr) => {
                const cols = Array.from(tr.querySelectorAll('td')).map(td => safeInnerText(td)).filter(Boolean);
                if(cols.length) lines.push('• ' + cols.join(' | '));
            });
            const note = safeInnerText(card.querySelector('.apai-agent-variation-preview-note'));
            if(note) lines.push(note);
        }

        return lines.join('\n');
    }

    function buildTranscriptText(){
        const container = document.getElementById('apai_agent_messages');
        if(!container) return '';

        const blocks = [];
        Array.from(container.children).forEach((node) => {
            if(!(node instanceof HTMLElement)) return;

            if(node.classList.contains('apai-agent-message')){
                const b = extractMessageBlock(node);
                if(b) blocks.push(b);
                return;
            }

            if(node.classList.contains('apai-agent-action-card')){
                const b = extractActionCardBlock(node);
                if(b) blocks.push(b);
                return;
            }
        });

        return blocks.join('\n');
    }

    function getDebugFullText(){
        const pre = document.getElementById('apai_agent_debug_pre');
        const levelSel = document.getElementById('apai_agent_debug_level');

        const level = levelSel ? String(levelSel.value || '') : '';
        const debug = safeInnerText(pre);
        if(!debug) return '';

        return '=== DEBUG ' + (level ? level.toUpperCase() : 'FULL') + ' ===\n' + debug;
    }

    async function getTracerText(){
        if(!window.APAI_Trace || typeof window.APAI_Trace.getCombinedText !== 'function') return '';
        try{
            const combined = await window.APAI_Trace.getCombinedText();
            if(!combined) return '';
            return '=== TRACER (esta conversación) ===\n' + combined;
        }catch(e){
            return '';
        }
    }

    async function copyAll(){
        const transcript = buildTranscriptText();
        const debug = getDebugFullText();
        const tracer = await getTracerText();

        const parts = [];
        parts.push('=== AUTOPRODUCT AI CHAT ===');
        if(transcript) parts.push(transcript);
        if(debug) parts.push(debug);
        if(tracer) parts.push(tracer);

        const text = parts.join('\n\n');
        if(!text.trim()) return false;

        // Clipboard
        if(navigator.clipboard && navigator.clipboard.writeText){
            await navigator.clipboard.writeText(text);
            return true;
        }

        // Fallback
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.top = '-9999px';
        document.body.appendChild(ta);
        ta.focus();
        ta.select();
        const ok = document.execCommand('copy');
        document.body.removeChild(ta);
        return ok;
    }

    function toast(msg){
        try{
            const t = document.createElement('div');
            t.className = 'apai-toast';
            t.textContent = msg;
            document.body.appendChild(t);
            setTimeout(() => { t.classList.add('show'); }, 10);
            setTimeout(() => { t.classList.remove('show'); }, 1600);
            setTimeout(() => { if(t && t.parentNode) t.parentNode.removeChild(t); }, 2200);
        }catch(e){
            // Silent
        }
    }

    function bind(){
        const btn = document.getElementById('apai_agent_copy_all') || document.getElementById('apai_copy_conversation') || document.querySelector('.apai-btn-copy');
        if(!btn) return;

        const labelEl = btn.querySelector('.apai-btn-copy-label') || btn;

        btn.addEventListener('click', async (e) => {
            e.preventDefault();
            const prev = labelEl.textContent;
            btn.disabled = true;
            labelEl.textContent = 'Copiando…';

            try{
                const ok = await copyAll();
                toast(ok ? 'Copiado ✅' : 'No se pudo copiar');
            }catch(err){
                console.error(err);
                toast('No se pudo copiar');
            }

            labelEl.textContent = prev;
            btn.disabled = false;
        });
    }

    document.addEventListener('DOMContentLoaded', bind);

})();
