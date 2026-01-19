(function(){
    'use strict';

    window.APAI_AGENT_UI = window.APAI_AGENT_UI || {};
    const UI = window.APAI_AGENT_UI;

    UI.coreui = UI.coreui || {};


    // Chat scroll is internal (messages container), like chatgpt.com.
    // The browser page should not need to scroll to use the chat.
    let __apaiScroller = null;
    function ensureScroller(){
        if(__apaiScroller) return __apaiScroller;
        try{
            if(UI && UI.layout && UI.layout.createAutoScroller){
                __apaiScroller = UI.layout.createAutoScroller();
                return __apaiScroller;
            }
        }catch(e){}
        __apaiScroller = { scroll: function(){} };
        return __apaiScroller;
    }
    function scrollMessagesToBottom(force){
        try{ ensureScroller().scroll(!!force); }catch(e){}
    }

// ============================================================
    // Action-card concurrency guard
    //
    // Users can click "Confirmar" and "Cancelar" very fast, causing
    // two requests to race and the UI to show contradictory states.
    // We enforce a single in-flight action at a time (global lock)
    // and disable all buttons in the pending card until the request
    // finishes.
    // ============================================================

    // Action lock shared across files (core-ui/core-cards).
    // Previously this lived only inside this IIFE, so core-cards couldn't see it.
    // Expose it on window to make card buttons (ejecutar/cancelar) work.
    window.__apaiActionLock = window.__apaiActionLock || {
        locked: false,
        key: '',
        lock: function(key){
            key = key || 'action';
            if (this.locked) { return false; }
            this.locked = true;
            this.key = key;
            return true;
        },
        unlock: function(){
            this.locked = false;
            this.key = '';
        }
    };

    // Keep a local alias for readability inside this file.
    var __apaiActionLock = window.__apaiActionLock;

    function lockActionCard(card, mode){
        if (!card) { return; }
        card.dataset.apaiLocked = '1';

        // Disable *all* buttons inside the card to avoid races.
        var buttons = card.querySelectorAll('button');
        buttons.forEach(function(btn){
            if (!btn.dataset.apaiOrigText) {
                btn.dataset.apaiOrigText = btn.textContent;
            }
            btn.disabled = true;
        });

        // Buttons created in addActionCard()
        var confirmBtn = card.querySelector('.apai-confirm-btn');
        var cancelBtn  = card.querySelector('.apai-cancel-btn');

        if (mode === 'confirm') {
            if (confirmBtn) { confirmBtn.textContent = 'Ejecutando...'; }
            if (cancelBtn)  { cancelBtn.textContent  = '...'; }
        } else if (mode === 'cancel') {
            if (cancelBtn)  { cancelBtn.textContent  = 'Cancelando...'; }
            if (confirmBtn) { confirmBtn.textContent = '...'; }
        }
    }

    function unlockActionCard(card){
        if (!card) { return; }
        delete card.dataset.apaiLocked;

        var buttons = card.querySelectorAll('button');
        buttons.forEach(function(btn){
            if (btn.dataset.apaiOrigText) {
                btn.textContent = btn.dataset.apaiOrigText;
            }
            btn.disabled = false;
        });
    }
    // ============================================================
    // AutoProduct AI — Brain Admin Chat UI
    //
    // @UI_CONTRACT (invariant)
    // - The UI must show action buttons ONLY when the server says
    //   store_state.pending_action != null.
    // - If pending_action is null, the UI MUST clear any previous
    //   action panel to avoid stale buttons.
    //
    // Navigation tips for humans/IA:
    // - Search for "renderAction" to find where Confirm/Cancel is drawn
    // - Search for "pending_action" to find server-truth checks
    // ============================================================

    // --- Debug Context UI ---
    let debugEnabled = false;
    let lastDebug = null;
    let lastQA = null;

    function renderDebugPre(){
        const pre = document.getElementById('apai_agent_debug_pre');
        if(!pre || !debugEnabled) return;

        let payload = null;
        if(lastDebug && lastQA){ payload = { debug: lastDebug, qa: lastQA }; }
        else if(lastDebug){ payload = lastDebug; }
        else if(lastQA){ payload = lastQA; }
        else { payload = { ok: true, info: 'No debug/QA data yet.' }; }

        try{ pre.textContent = JSON.stringify(payload, null, 2); }catch(e){ pre.textContent = String(payload); }
    }

    function ensureDebugOpen(){
        if(debugEnabled) return;
        const toggle = document.getElementById('apai_agent_debug_toggle');
        if(toggle){
            try{ toggle.click(); return; }catch(e){}
        }
        // Fallback: open without the toggle handler
        debugEnabled = true;
        const wrap = document.getElementById('apai_agent_debug_wrap');
        if(wrap) wrap.style.display = 'block';
        renderDebugPre();
    }


    function bindExistingDebugUI(){
        const toggle = document.getElementById('apai_agent_debug_toggle');
        const wrap = document.getElementById('apai_agent_debug_wrap');
        const pre = document.getElementById('apai_agent_debug_pre');
        if(!toggle || !wrap || !pre) return false;
        if(toggle.__apaiBound) return true;

        toggle.__apaiBound = true;
        toggle.addEventListener('click', function(){
            debugEnabled = !debugEnabled;
            wrap.style.display = debugEnabled ? 'block' : 'none';
            toggle.textContent = debugEnabled ? 'Ocultar Debug' : 'Mostrar Debug';
            if(debugEnabled){
                renderDebugPre();
                if(!lastDebug){ fetchDebug(); }
                scrollMessagesToBottom(false);
            }
        });

        const lvlEl = document.getElementById('apai_agent_debug_level');
        if(lvlEl && !lvlEl.__apaiBound){
            lvlEl.__apaiBound = true;
            lvlEl.addEventListener('change', function(){
                if(debugEnabled){ lastDebug = null; fetchDebug(); }
            });
        }
        return true;
    }

    function bindExistingQAUI(){
        const btnQuick = document.getElementById('apai_agent_qa_quick');
        const btnVerbose = document.getElementById('apai_agent_qa_verbose');
    const btnRegre = document.getElementById('apai_agent_qa_regression');
        if(!btnQuick && !btnVerbose) return false;

        function setButtonsLoading(on, label){
            try{
                [btnQuick, btnVerbose].forEach(function(b){
                    if(!b) return;
                    if(!b.dataset.apaiOrigText){ b.dataset.apaiOrigText = b.textContent; }
                    b.disabled = !!on;
                    if(on && label){ b.textContent = label; }
                    if(!on && b.dataset.apaiOrigText){ b.textContent = b.dataset.apaiOrigText; }
                });
            }catch(e){}
        }

        function runQA(verbose){
            if(!window.APAI_AGENT_DATA) return;
            const url = verbose ? APAI_AGENT_DATA.qa_url : APAI_AGENT_DATA.qa_url_quick;
            if(!url) return;

            ensureDebugOpen();
            setButtonsLoading(true, 'QA...');
            const t0 = Date.now();

            const mode = verbose ? 'verbose' : 'quick';
            try{ console.groupCollapsed('APAI QA (' + mode + ')'); }catch(e){}

            fetch(url, {
                method: 'GET',
                headers: { 'X-WP-Nonce': APAI_AGENT_DATA.nonce }
            })
                .then(function(r){
                    return r.json().then(function(data){ return { data: data, status: r.status }; });
                })
                .then(function(res){
                    const data = res && res.data ? res.data : { ok: false, error: 'empty response' };
                    data.__client = { mode: mode, status: res.status, ms: Date.now() - t0 };
                    try{
                        console.log('report:', data);
                        if(Array.isArray(data.checks)){
                            const failed = data.checks.filter(function(c){ return !c.ok; });
                            if(failed.length){ console.warn('failed checks:', failed); }
                            else { console.log('all checks ok'); }
                        }
                    }catch(e){}

                    lastQA = data;
                    renderDebugPre();
                })
                .catch(function(err){
                    const data = { ok: false, error: String(err && err.message ? err.message : err) };
                    data.__client = { mode: mode, status: 0, ms: Date.now() - t0 };
                    try{ console.error('QA error:', err); }catch(e){}
                    lastQA = data;
                    renderDebugPre();
                })
                .finally(function(){
                    try{ console.groupEnd(); }catch(e){}
                    setButtonsLoading(false);
                    scrollMessagesToBottom(false);
                });
        }

        if(btnQuick && !btnQuick.__apaiBound){
            btnQuick.__apaiBound = true;
            btnQuick.addEventListener('click', function(){ runQA(false); });
        }
        if(btnVerbose && !btnVerbose.__apaiBound){
            btnVerbose.__apaiBound = true;
            btnVerbose.addEventListener('click', function(){ runQA(true); });
        }

        // F6.7 — botón REG (Regression Harness)
        // Abrimos el endpoint con nonce y una ventana de tiempo chica para evitar ruido.
        if(btnRegre && !btnRegre.__apaiBound){
            btnRegre.__apaiBound = true;
            btnRegre.addEventListener('click', function(){
                try{
                    const base = (window.APAI_AGENT_DATA && window.APAI_AGENT_DATA.qa_regression_url) ? window.APAI_AGENT_DATA.qa_regression_url : '/wp-json/autoproduct-ai/v1/qa/regression';
                    const nonce = (window.APAI_AGENT_DATA && window.APAI_AGENT_DATA.nonce) ? window.APAI_AGENT_DATA.nonce : '';
                    const minutes = 15; // default
                    const url = base + '?limit=200&minutes=' + encodeURIComponent(minutes) + '&_wpnonce=' + encodeURIComponent(nonce);
                    window.open(url, '_blank');
                }catch(e){
                    console.warn('[APAI] Regression button failed', e);
                }
            });
        }
        return true;
    }

    function ensureDebugUI(){
        // Debug/QA UI is rendered in PHP (inside the chat) — just bind events.
        bindExistingDebugUI();
        bindExistingQAUI();
    }

    function updateDebugPanel(dbg){
        lastDebug = dbg;
        renderDebugPre();
    }

    // --- Landing (first impression) ---
    function renderLanding(){
        const container = document.getElementById('apai_agent_messages');
        if(!container) return;

        // Only render if there is no conversation yet.
        const hasAnyMessage = !!container.querySelector('.apai-agent-message');
        const hasAnyCard = !!container.querySelector('.apai-agent-action-card, .apai-agent-target-card');
        const hasLanding = !!container.querySelector('.apai-landing');
        if(hasAnyMessage || hasAnyCard || hasLanding) return;

        container.innerHTML = '';

        const box = document.createElement('div');
        box.className = 'apai-landing';

        const title = document.createElement('div');
        title.className = 'apai-landing-title';
        title.textContent = '¿Por dónde empezamos?';

        const sub = document.createElement('div');
        sub.className = 'apai-landing-sub';
        sub.textContent = 'Escribí un pedido para administrar tu WooCommerce.';

        box.appendChild(title);
        box.appendChild(sub);
        container.appendChild(box);

        try{
            const chat = document.getElementById("apai_agent_chat");
            if(chat) chat.classList.add("apai-empty");
        }catch(e){}
    }

    function clearLanding(){
        const container = document.getElementById('apai_agent_messages');
        if(!container) return;
        const landing = container.querySelector('.apai-landing');
        if(landing){
            try{ landing.remove(); }catch(e){ container.innerHTML = ''; }
        }
    }

    // Anti-double-send: keep legacy global lock but centralize release.
    function releaseSendLock(){
        try{
            if(window.__apaiSendLock){
                window.__apaiSendLock.inFlight = false;
            }
        }catch(e){}
    }

    function fetchDebug(){
        if(!window.APAI_AGENT_DATA || !APAI_AGENT_DATA.debug_url) return;
        const lvlEl = document.getElementById('apai_agent_debug_level');
        const level = lvlEl ? (lvlEl.value || 'lite') : 'lite';
        const url = APAI_AGENT_DATA.debug_url + (APAI_AGENT_DATA.debug_url.indexOf('?')>-1 ? '&' : '?') + 'level=' + encodeURIComponent(level);
        fetch(url, {
            method: 'GET',
            headers: { 'X-WP-Nonce': APAI_AGENT_DATA.nonce }
        })
	        .then(r => r.json().then(data => ({ data, headers: r.headers })))
	        .then(({ data, headers }) => {
            // Anti-double-send: liberar lock al recibir respuesta.
            releaseSendLock();
            if(data && data.ok){
                updateDebugPanel(data);
            }
        })
        .catch(() => {});
    }

// --- Session state (short memory) ---
function loadSessionState(){
    try{
        const raw = localStorage.getItem('apai_session_state');
        if(!raw) return {};
        const obj = JSON.parse(raw);
        return (obj && typeof obj === 'object') ? obj : {};
    }catch(e){ return {}; }
}

// Per-tab id (NO compartido entre pestañas). Sirve para follow-ups humanos sin asumir continuidad entre pestañas.
function getTabId(){
    try{
        let id = sessionStorage.getItem('apai_tab_id');
        if(id) return id;
        id = 't_' + Math.random().toString(36).slice(2) + '_' + Date.now();
        sessionStorage.setItem('apai_tab_id', id);
        return id;
    }catch(e){
        // Fallback: si sessionStorage falla, igual generamos uno en memoria (no persistente)
        if(window.__apaiTabId) return window.__apaiTabId;
        window.__apaiTabId = 't_' + Math.random().toString(36).slice(2) + '_' + Date.now();
        return window.__apaiTabId;
    }
}

// Per-tab instance (NO persistente). Evita que un "duplicar pestaña" herede el mismo tab_id.
// - tab_id: estable dentro de la pestaña (sessionStorage)
// - tab_instance: cambia por carga de página (memoria JS)
function getTabInstance(){
    try{
        if(window.__apaiTabInstance) return window.__apaiTabInstance;
        window.__apaiTabInstance = 'i_' + Math.random().toString(36).slice(2) + '_' + Date.now();
        return window.__apaiTabInstance;
    }catch(e){
        return 'i_' + Math.random().toString(36).slice(2) + '_' + Date.now();
    }
}
function saveSessionState(state){
    try{
        localStorage.setItem('apai_session_state', JSON.stringify(state || {}));
    }catch(e){}
}
function setLastProduct(prod){
    if(!prod || !prod.id) return;
    const st = loadSessionState();
    st.last_product = { id: prod.id, name: (prod.name || prod.title || '') };
    saveSessionState(st);
}

 // última acción propuesta (aún no ejecutada)

    
    // Render special "Comprar tokens" line as a button (no HTML injection; we build DOM nodes).
    function renderBuyTokensButton(body, text){
        if(!body || !text || typeof text !== 'string') return false;
        if(text.indexOf('Comprar tokens:') === -1) return false;

        try{
            const lines = String(text).split(/\r?\n/);
            let handled = false;
            // Clear existing content
            while(body.firstChild) body.removeChild(body.firstChild);

            lines.forEach(line => {
                const raw = (line || '').trim();
                if(!raw) return;

                const m = raw.match(/^Comprar\s+tokens:\s*(https?:\/\/\S+)\s*$/i);
                if(m && m[1]){
                    handled = true;
                    const a = document.createElement('a');
                    a.className = 'apai-buytokens-btn';
                    a.href = m[1];
                    a.target = '_blank';
                    a.rel = 'noopener noreferrer';
                    a.textContent = 'Comprar tokens';
                    body.appendChild(a);
                    return;
                }

                const div = document.createElement('div');
                div.className = 'apai-agent-line';
                div.textContent = line;
                body.appendChild(div);
            });

            return handled;
        }catch(e){
            // Fallback to default rendering
            try{ body.textContent = text || ''; }catch(_e){}
            return false;
        }
    }

function addMessage(role, text){
        const container = document.getElementById('apai_agent_messages');
        if(!container) return;

        // Normalize legacy role name
        if(role === 'agent') role = 'assistant';

        // If we are in landing mode, clear it as soon as the first real message is added.
        clearLanding();

        try{
            const chat = document.getElementById("apai_agent_chat");
            if(chat) chat.classList.remove("apai-empty");
        }catch(e){}

        const wrap = document.createElement('div');
        wrap.className = 'apai-agent-message apai-agent-' + role;

        const label = document.createElement('div');
        label.className = 'apai-agent-label';
        label.textContent = role === 'user' ? 'Tú:' : 'Agente:';

        const body = document.createElement('div');
        body.className = 'apai-agent-text';
        body.textContent = text;

        const bubble = document.createElement('div');
        bubble.className = 'apai-agent-bubble';
        bubble.appendChild(body);

        wrap.appendChild(label);
        wrap.appendChild(bubble);
        container.appendChild(wrap);

        scrollMessagesToBottom(role === 'user');
    }


    // Assistant message with ChatGPT-like typewriter animation.
    // Keeps UX smooth (no big instant jumps).
    function addAssistantTyped(text){
        const container = document.getElementById('apai_agent_messages');
        if(!container) return Promise.resolve();
        clearLanding();

        try{
            const chat = document.getElementById("apai_agent_chat");
            if(chat) chat.classList.remove("apai-empty");
        }catch(e){}

        const wrap = document.createElement('div');
        wrap.className = 'apai-agent-message apai-agent-assistant';

        const bubble = document.createElement('div');
        bubble.className = 'apai-agent-bubble';

        const body = document.createElement('div');
        body.className = 'apai-agent-text';

        bubble.appendChild(body);
        wrap.appendChild(bubble);
        container.appendChild(wrap);

        scrollMessagesToBottom(true);

        // Special case: show buy-tokens button instead of plain URL
        if(renderBuyTokensButton(body, text || '')){
            scrollMessagesToBottom(true);
            return Promise.resolve();
        }

        try{
            if(UI && UI.typing && UI.typing.typewriter){
                return UI.typing.typewriter(body, text || '', { minTick: 10, maxTick: 18 })
                    .then(() => { scrollMessagesToBottom(true); });
            }
        }catch(e){}
        body.textContent = text || '';
        scrollMessagesToBottom(true);
        return Promise.resolve();
    }


    function closeActionCard(card, label){
        if(!card) return;
        // Hide action buttons permanently for this card (confirm/cancel/update-to-new)
        const btns = card.querySelectorAll('button[data-apai-action]');
        btns.forEach(b => { b.disabled = true; b.style.display = 'none'; });

        // Add (once) a small status line
        if(!card.querySelector('.apai-agent-action-done')){
            const done = document.createElement('div');
            done.className = 'apai-agent-action-done';
            done.textContent = label || '✅ Acción ejecutada.';
            done.style.marginTop = '8px';
            done.style.fontSize = '13px';
            done.style.opacity = '0.9';
            card.appendChild(done);
        }

        // Mark as closed so it can remain in the conversation history
        try{ card.dataset.apaiState = 'closed'; }catch(e){}

        // Keep the card in the conversation history.
    }


    // Small helper used by core.js to prevent double-submits and keep UX consistent.
    function setChatLoading(isLoading){
        const inputEl = document.getElementById('apai_agent_input');
        const sendBtn = document.getElementById('apai_agent_send');
        const agentSel = document.getElementById('apai_agent_selector');
        const on = !!isLoading;

        try{ if(inputEl) inputEl.disabled = on; }catch(e){}
        try{ if(sendBtn) sendBtn.disabled = on; }catch(e){}
        try{ if(agentSel) agentSel.disabled = on; }catch(e){}

        try{
            const shell = document.getElementById('apai_agent_shell');
            if(shell) shell.classList.toggle('apai-loading', on);
        }catch(e){}
    }


    // Exports
    UI.coreui.ensureScroller = ensureScroller;
    UI.coreui.scrollMessagesToBottom = scrollMessagesToBottom;

    // Core state helpers (shared across split files)
    UI.coreui.getTabId = getTabId;
    UI.coreui.getTabInstance = getTabInstance;
    UI.coreui.loadSessionState = loadSessionState;
    UI.coreui.saveSessionState = saveSessionState;
    UI.coreui.setChatLoading = setChatLoading;

    UI.coreui.lockActionCard = lockActionCard;
    UI.coreui.unlockActionCard = unlockActionCard;

    UI.coreui.ensureDebugUI = ensureDebugUI;
    UI.coreui.updateDebugPanel = updateDebugPanel;
    UI.coreui.fetchDebug = fetchDebug;
    UI.coreui._renderDebugPre = renderDebugPre;
    UI.coreui.closeActionCard = closeActionCard;

    // Back-compat: some modules call fetchDebug() directly (pre-split core.js).
    // Expose it globally to avoid ReferenceError in core-cards.js.
    window.fetchDebug = fetchDebug;
    window.closeActionCard = closeActionCard;
    UI.coreui.isDebugEnabled = function(){ return !!debugEnabled; };

    UI.coreui.renderLanding = renderLanding;
    UI.coreui.clearLanding = clearLanding;

    UI.coreui.addMessage = addMessage;
    UI.coreui.addAssistantTyped = addAssistantTyped;

    // Back-compat globals (core.js was originally a single file and used direct function calls).
    // Keeping these avoids a large mechanical refactor while core.js is being split.
    try{
        window.getTabId = getTabId;
        window.getTabInstance = getTabInstance;
        window.loadSessionState = loadSessionState;
        window.saveSessionState = saveSessionState;
        window.addMessage = addMessage;
        window.setChatLoading = setChatLoading;
    }catch(e){}
})();