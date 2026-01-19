(function(){
    'use strict';
    // Ensure send-lock release helper exists (used by split JS files)
    function releaseSendLock(){
        try {
            if (window.__apaiSendLock) {
                window.__apaiSendLock.inFlight = false;
            }
        } catch (e) {}
    }
    // Expose for any legacy/global references
    window.releaseSendLock = window.releaseSendLock || releaseSendLock;


    window.APAI_AGENT_UI = window.APAI_AGENT_UI || {};
    const UI = window.APAI_AGENT_UI;

    // Controller state (kept here intentionally)
    let history = [];

    // Expose sendMessage for cards/buttons (back-compat: boolean args or opts object)
    UI.sendMessage = function(messageOverride, arg2, arg3){
        if (typeof sendMessage !== 'function') return;
        let opts = {};
        if (arg2 && typeof arg2 === 'object' && !Array.isArray(arg2)) {
            opts = Object.assign({}, arg2);
        } else {
            opts.silentUser = !!arg2;
            if (typeof arg3 === 'boolean') opts.bypassClientGuard = arg3;
        }
        // Back-compat keys
        if (typeof opts.silentUser === 'undefined' && typeof opts.silentUserEcho !== 'undefined') {
            opts.silentUser = !!opts.silentUserEcho;
        }
        if (typeof opts.replaySilentUser === 'undefined' && typeof opts.replaySilentUserEcho !== 'undefined') {
            opts.replaySilentUser = !!opts.replaySilentUserEcho;
        }
        return sendMessage(messageOverride, opts);
    };
    function sendMessage(msgOverride, opts){
        const input = document.getElementById('apai_agent_input');
        let msg = '';
        if(typeof msgOverride === 'string'){
            msg = msgOverride.trim();
        }else{
            if(!input || !input.value.trim()) return Promise.resolve(null);
            msg = input.value.trim();
            input.value = '';
        }

        // Si hay un cancel en vuelo, encolamos el mensaje del usuario (para evitar carreras).
        try{
            if(window.__apaiCancelInFlight && msg !== 'cancelar'){
                window.__apaiQueuedMsg = { msg: msg, opts: opts || {} };
                // Mostramos el mensaje del usuario igual (si corresponde) para no romper UX.
                const silentUserQ = !!(opts && opts.silentUser);
                if(!silentUserQ){ UI.coreui.addMessage('user', msg); }
                return Promise.resolve({ queued: true });
            }
        }catch(e){}
        // Anti-double-send: evita duplicados por doble Enter/click (y por latencia).
		try{
            if(!window.__apaiSendLock){ window.__apaiSendLock = { msg:'', ts:0, inFlight:false }; }
            const now = Date.now();
            const same = (window.__apaiSendLock.msg === msg);
			// IMPORTANT: always return a Promise so callers using .then/.finally don't break
			// even when we skip duplicated sends.
			if((same && (now - window.__apaiSendLock.ts) < 800) || (window.__apaiSendLock.inFlight && same)){
				return Promise.resolve({ skipped: true, reason: 'duplicate_send' });
			}
            window.__apaiSendLock.msg = msg;
            window.__apaiSendLock.ts = now;
            window.__apaiSendLock.inFlight = true;
        }catch(e){}

        // Request ordering guard: evita que respuestas viejas (llegando tarde) pisen el estado UI.
        // @INVARIANT: no cambia comportamiento del backend; solo descarta renders out-of-order en el frontend.
        // WHY: prevenir carreras (multi-fetch) que pueden mostrar "✅ Acción cancelada" fuera de contexto.
        try{
            if(typeof window.__apaiReqSeq !== 'number'){ window.__apaiReqSeq = 0; }
            if(typeof window.__apaiLatestReqId !== 'number'){ window.__apaiLatestReqId = 0; }
        }catch(e){}
        let __reqId = 0;
        try{
            __reqId = ++window.__apaiReqSeq;
            window.__apaiLatestReqId = __reqId;
        }catch(e){}

        const silentUser = !!(opts && opts.silentUser);
        const silentAssistant = !!(opts && opts.silentAssistant);
        if(!silentUser){ UI.coreui.addMessage('user', msg); }

        // Typing indicator (ChatGPT-like)
        let __apaiTypingNode = null;
        try{
            if(!silentAssistant && UI && UI.typing && UI.typing.show){
                __apaiTypingNode = UI.typing.show(document.getElementById('apai_agent_messages'));
                UI.coreui.scrollMessagesToBottom(true);
            }
        }catch(e){}


        const payload = {
            message: msg,
            history: history,
            session_state: loadSessionState(),
            tab_id: getTabId(),
            tab_instance: getTabInstance()
        };

        const btn = document.getElementById('apai_agent_send');
        if(btn){
            btn.disabled = true;
            btn.textContent = 'Pensando...';
        }

        return fetch(APAI_AGENT_DATA.rest_url, {
            method: 'POST',
            headers: {
                'X-WP-Nonce': APAI_AGENT_DATA.nonce,
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload)
        })
	        .then(r => r.json().then(data => ({ data, headers: r.headers })))
	        .then(({ data, headers }) => {
            // Anti-double-send: liberar lock al recibir respuesta.
            releaseSendLock();

            // Si esta respuesta no corresponde al último request enviado, la descartamos (llegó tarde).
            // Esto evita que respuestas viejas re-rendericen cards/eventos sobre una interacción más nueva.
            try{
                if(typeof window.__apaiLatestReqId === 'number' && __reqId && __reqId !== window.__apaiLatestReqId){
                    // Igual re-habilitamos el botón y salimos.
                    if(btn){ btn.disabled = false; btn.textContent = 'Enviar'; }
                    return;
                }
            }catch(e){}
            if(btn){
                btn.disabled = false;
                btn.textContent = 'Enviar';
            }

            try{ if(__apaiTypingNode && UI && UI.typing && UI.typing.remove){ UI.typing.remove(__apaiTypingNode); __apaiTypingNode = null; } }catch(e){}

            if(!data.ok){
                const msg = data.message || data.error || data.code || 'Error al llamar al agente.';
                if(!silentAssistant){ UI.coreui.addMessage('assistant', 'Error: ' + msg); }
                console.error(data);
                return;
            }

	            // UI-only: keep trace_id for this conversation (used by the Copy button).
	            try{
	                let tid = '';
	                if(data && data.meta && data.meta.trace_id){
	                    tid = String(data.meta.trace_id);
	                } else if(data && data.trace_id){
	                    tid = String(data.trace_id);
	                }
	                if(!tid && headers && typeof headers.get === 'function'){
	                    tid = String(headers.get('x-apai-trace-id') || headers.get('X-APAI-Trace-Id') || '');
	                }
	                if(tid && window.APAI_Trace && typeof window.APAI_Trace.add === 'function'){
	                    window.APAI_Trace.add(tid);
	                }
	            }catch(e){}

	            if(!silentAssistant){
                UI.coreui.addAssistantTyped(data.reply || '(Respuesta vacía)');
            }

	            // Frontend safety-net: sometimes the backend returns the pending-choice prompt
	            // but omits meta.pending_choice in lite responses. If we can detect that the
	            // assistant is asking what to do with an existing pending action, we force the
	            // pending card into "choice" mode and use the user's last message as the
	            // deferred command to replay if the user chooses to update to the new action.
	            try {
	                const hasPending = !!(data && data.store_state && data.store_state.pending_action);
	                const hasChoiceMeta = !!(data && data.meta && data.meta.pending_choice);
					const replyText = (data && data.reply) ? String(data.reply) : '';
					// Más tolerante: con que diga "Tenés una acción pendiente" ya mostramos la tarjeta
					// con botones de elección. Evita quedar "colgado" sin UI.
					const looksLikePendingChoice = /ten[eé]s una acci[oó]n pendiente/i.test(replyText);
	
	                if (hasPending && !hasChoiceMeta && looksLikePendingChoice) {
	                    data.meta = data.meta || {};
	                    data.meta.pending_choice = 'swap_to_deferred';
	                    if (!data.meta.deferred_message) {
	                        data.meta.deferred_message = msg;
	                    }
	                }
	            } catch (e) {
	                // ignore
	            }
            // Actualizar snapshot de debug (store_state + conteos) en cada respuesta.
            UI.coreui.fetchDebug();
            history.push({ role: 'user', content: payload.message });

            // Para robustez: si el backend propone una acción, guardamos la salida del asistente como JSON
            // (así el modelo ve siempre "assistant -> JSON" en el historial y reduce respuestas no-JSON).
            if(data.action){
                try{
                    history.push({ role: 'assistant', content: JSON.stringify({ reply: data.reply || '', action: data.action }) });
                }catch(e){
                    history.push({ role: 'assistant', content: data.reply });
                }
            }else{
                history.push({ role: 'assistant', content: data.reply });
            }

            // Pending action rendering:
            // We render (or clear) the pending card ONLY when the server state changes.
            // This prevents the same card from being appended again after A1–A8 queries.
            UI.corecards.ensurePendingCardFromServerTruth(data);
							UI.corecards.ensureTargetSelectionCardFromServerTruth(data);

            // Si este envío era un "cancelar" disparado por botón y tenemos un replayAfter,
            // re-enviamos el mensaje original automáticamente una vez que el backend confirmó que limpió pending.
            try{
                const replay = (opts && opts.replayAfter) ? String(opts.replayAfter) : '';
                if(replay){
                    const backendCleared = !!(data && data.meta && data.meta.should_clear_pending);
                    const pending = (data && data.store_state) ? data.store_state.pending_action : null;
                    const cleared = backendCleared || !pending;
                    if(cleared){
                        // Pequeña protección para evitar loops.
                        if(!window.__apaiReplayLock){ window.__apaiReplayLock = {}; }
                        if(!window.__apaiReplayLock[replay]){
                            window.__apaiReplayLock[replay] = true;
                            const silentReplay = (opts && typeof opts.replaySilentUser !== 'undefined') ? !!opts.replaySilentUser : true;
                            setTimeout(() => sendMessage(replay, { silentUser: silentReplay }), 150);
                        }
                    }
                }
            }catch(e){}

            // Si el backend marcó que debemos ejecutar ya una acción pendiente (por confirmación del usuario),
            // ejecutamos automáticamente SIN volver a pedir click al botón.
            // Nota: NO auto-ejecutamos acciones por texto. Siempre se requiere click en Confirmar.
            // (Seguridad > fluidez: evita ejecuciones accidentales por intent resolver).

            // (Nota) replayAfter ya se maneja arriba con locks; no duplicar aquí.
	            return data;
	        })
        .catch(err => {
            console.error(err);
            releaseSendLock();
            if(btn){
                btn.disabled = false;
                btn.textContent = 'Enviar';
            }
            try{ if(__apaiTypingNode && UI && UI.typing && UI.typing.remove){ UI.typing.remove(__apaiTypingNode); __apaiTypingNode = null; } }catch(e){}
            UI.coreui.addMessage('assistant', 'Error de red al contactar al agente.');
        });
    }

    document.addEventListener('DOMContentLoaded', function(){
        // Make trace copy session-scoped: avoid leaking trace ids from previous page loads.
        try {
            if(window.APAI_Trace && typeof window.APAI_Trace.reset === 'function'){
                window.APAI_Trace.reset();
            }
        } catch(e) {}
        const btn = document.getElementById('apai_agent_send');
        const input = document.getElementById('apai_agent_input');

        // Fit the chat frame to the first viewport (no page scroll on load)
        function resizeChatFrame(){
            try{
                if(UI && UI.layout && UI.layout.fitViewport){
                    UI.layout.fitViewport();
                }
            }catch(e){}
        }
        resizeChatFrame();
        window.addEventListener('resize', resizeChatFrame);

        // Internal autoscroll (messages container)
        UI.coreui.ensureScroller();

        // Debug UI (siempre disponible)
        UI.coreui.ensureDebugUI();

        // Bootstrap: si hay pending_action server-side (ej. nueva pestaña), mostrar card y botones al cargar.
        try{
            if(window.APAI_AGENT_DATA && APAI_AGENT_DATA.debug_url){
                const url = APAI_AGENT_DATA.debug_url + (APAI_AGENT_DATA.debug_url.indexOf('?')>-1 ? '&' : '?') + 'level=lite';
                fetch(url, { method:'GET', headers:{ 'X-WP-Nonce': APAI_AGENT_DATA.nonce } })
                    .then(r => r.json())
                    .then(data => {
                        try{
                            const pa = (data && data.store_state) ? data.store_state.pending_action : null;

                            if(pa){
                                // Pending exists: show the card (no greeting message).
                                UI.coreui.clearLanding();

        try{
            const chat = document.getElementById("apai_agent_chat");
            if(chat) chat.classList.remove("apai-empty");
        }catch(e){}
                                UI.corecards.ensurePendingCardFromServerTruth(data);
                                return;
                            }

                            // No pending: maybe there is a target selector.
                            UI.corecards.ensureTargetSelectionCardFromServerTruth(data);

                            const hasSel = !!(data && data.store_state && data.store_state.pending_target_selection);
                            if(!hasSel){
                                UI.coreui.renderLanding();
                            }else{
                                UI.coreui.clearLanding();

        try{
            const chat = document.getElementById("apai_agent_chat");
            if(chat) chat.classList.remove("apai-empty");
        }catch(e){}
                            }
                        }catch(e){
                            // If anything goes wrong, keep the landing (first impression)
                            UI.coreui.renderLanding();
                        }
                    })
                    .catch(()=>{ UI.coreui.renderLanding(); });
            }
        }catch(e){}

        // Fallback: if bootstrap did not render anything, keep the landing.
        setTimeout(() => { UI.coreui.renderLanding(); }, 0);

        // Ajustar texto del botón en el Brain.
        if(btn){
            btn.textContent = 'Enviar';
        }

        if(btn){
            btn.addEventListener('click', function(e){
                e.preventDefault();
                sendMessage();
            });
        }

        function autoGrowTextarea(el){
            try{
                if(!el) return;
                el.style.height = 'auto';
                el.style.height = Math.min(el.scrollHeight, 160) + 'px';
            }catch(e){}
        }

        if(input){
            // Auto-grow like ChatGPT
            input.addEventListener('input', function(){ autoGrowTextarea(input); });
            autoGrowTextarea(input);

            input.addEventListener('keydown', function(e){
                // Send on Enter (Shift+Enter for new line). Keep Ctrl/Cmd+Enter as well.
                if(e.isComposing) return;
                if(e.key === 'Enter' && (e.ctrlKey || e.metaKey)){
                    e.preventDefault();
                    sendMessage();
                    return;
                }
                if(e.key === 'Enter' && !e.shiftKey){
                    e.preventDefault();
                    sendMessage();
                    return;
                }
            });
        }
    });

})();