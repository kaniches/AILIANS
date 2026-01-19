(function(){
    'use strict';

    window.APAI_AGENT_UI = window.APAI_AGENT_UI || {};
    const UI = window.APAI_AGENT_UI;

	// Safety: core-ui exposes this lock globally. In case load order changes or a theme/plugin
	// interferes, define a fallback here so action buttons never break.
	window.__apaiActionLock = window.__apaiActionLock || {
		locked: false,
		key: '',
		lock: function(key){
			if (this.locked) { return false; }
			this.locked = true;
			this.key = key || 'action';
			return true;
		},
		unlock: function(){
			this.locked = false;
			this.key = '';
		}
	};
	var __apaiActionLock = window.__apaiActionLock;

    UI.corecards = UI.corecards || {};

    function closeAllActionCards(label){
        document.querySelectorAll('.apai-agent-action-card').forEach(card => closeActionCard(card, label));
    }

    // Elimina completamente las tarjetas de acción de la UI (para queries / respuestas sin pending).
    // Esto evita estado residual visual (botones/cajas antiguas) cuando el backend ya no tiene pending_action.
    function removeAllActionCards(){
        try{
            document.querySelectorAll('.apai-agent-action-card').forEach(card => {
                const state = card && card.dataset ? String(card.dataset.apaiState || '') : '';
                if(state === 'closed') return; // keep closed cards as history
                try{ card.remove(); }catch(e){ if(card && card.parentNode){ card.parentNode.removeChild(card); } }
            });
        }catch(e){}

        // (No dock) closed cards remain as history; open cards are removed.
    }

    // Stable key for the currently pending action.
    // Used to avoid re-rendering the same card on every response (e.g. A1–A8 queries).
    function apaiPendingKey(pending){
        if(!pending) return '';
        const t = pending.type || '';
        const ca = pending.created_at || '';
        const a = pending.action || {};
        const pid = a.product_id || pending.product_id || '';
        const hs = a.human_summary || pending.human_summary || '';
        return [t, ca, pid, hs].join('|');
    }

    // Tracks which pending action is already rendered in the history.
    // (Keeps behavior deterministic across messages and avoids UI jumps.)
    let apai_last_pending_key = '';


// --- Target selection (2–5 candidates) ---
function apaiTargetSelectionKey(sel){
    if(!sel) return '';
    const kind = sel.kind || '';
    const field = sel.field || '';
    const value = sel.value || '';
    const at = sel.asked_at || '';
    const ids = Array.isArray(sel.candidates) ? sel.candidates.map(c => (c && c.id) ? c.id : '').join(',') : '';
    return [kind, field, value, at, ids].join('|');
}

function removeAllTargetSelectionCards(){
    try{
        document.querySelectorAll('.apai-agent-target-card').forEach(card => {
            try{ card.remove(); }catch(e){ if(card && card.parentNode){ card.parentNode.removeChild(card); } }
        });
    }catch(e){}
    try{ window.__apai_last_target_sel_key = ''; }catch(e){}
}

// Compat helper: some callers expect a "closeAll*" API.
// For target selection we remove the cards entirely (they are not part of chat history).
function closeAllTargetSelectionCards(label){
    removeAllTargetSelectionCards();
}

function ensureTargetSelectionCardFromServerTruth(payload){
    try{
        const pa = (payload && payload.store_state) ? payload.store_state.pending_action : null;
        if(pa){
            // Pending action takes precedence; don't show selection at the same time.
            removeAllTargetSelectionCards();
            return;
        }
        const sel = (payload && payload.store_state) ? payload.store_state.pending_target_selection : null;
        const keyNow = apaiTargetSelectionKey(sel);
        const existing = document.querySelector('.apai-agent-target-card');

        if(!sel){
            removeAllTargetSelectionCards();
            return;
        }

        if(existing && window.__apai_last_target_sel_key && window.__apai_last_target_sel_key === keyNow){
            return;
        }

        removeAllTargetSelectionCards();
        addTargetSelectionCard(sel);
        try{ window.__apai_last_target_sel_key = keyNow; }catch(e){}
    }catch(e){}
}

function addTargetSelectionCard(sel){
    const container = document.getElementById('apai_agent_messages');
    if(!container || !sel) return;

    // We can show the selector even with an empty initial candidate list
    // because the user may want to search in a huge catalog.
    const initialCandidates = Array.isArray(sel.candidates) ? sel.candidates : [];
    let totalCount = typeof sel.total === 'number' ? sel.total : (Array.isArray(sel.candidates) ? sel.candidates.length : 0);
    const pageLimit = typeof sel.limit === 'number' && sel.limit > 0 ? sel.limit : 20;

    let currentQuery = (sel.query || '').toString();
    let selectedId = null;
    let loaded = [];

    const card = document.createElement('div');
    card.className = 'apai-agent-action-card apai-agent-target-card';

    const title = document.createElement('div');
    title.className = 'apai-agent-action-title';
    title.textContent = 'Elegí el producto';

    const summary = document.createElement('div');
    summary.className = 'apai-agent-action-summary';
    summary.textContent = 'Marcá un producto en la lista o buscá por nombre.';

    card.appendChild(title);
    card.appendChild(summary);

    // Search bar
    const searchWrap = document.createElement('div');
    searchWrap.className = 'apai-target-search';

    const searchInput = document.createElement('input');
    searchInput.type = 'text';
    searchInput.value = currentQuery;
    searchInput.placeholder = 'Buscar productos...';
    searchInput.className = 'apai-target-search-input';

    const searchBtn = document.createElement('button');
    searchBtn.type = 'button';
    searchBtn.className = 'button button-secondary apai-target-search-btn';
    searchBtn.textContent = 'Buscar';

    searchWrap.appendChild(searchInput);
    searchWrap.appendChild(searchBtn);
    card.appendChild(searchWrap);

    // Meta (count)
    const meta = document.createElement('div');
    meta.className = 'apai-target-meta';
    card.appendChild(meta);

    // List
    const list = document.createElement('div');
    list.className = 'apai-target-list';
    card.appendChild(list);

    // Footer actions
    const actions = document.createElement('div');
    actions.className = 'apai-target-actions';

    const pickBtn = document.createElement('button');
    pickBtn.type = 'button';
    pickBtn.className = 'button button-primary';
    pickBtn.textContent = 'Seleccionar';
    pickBtn.disabled = true;

    const loadMoreBtn = document.createElement('button');
    loadMoreBtn.type = 'button';
    loadMoreBtn.className = 'button button-secondary';
    loadMoreBtn.textContent = 'Cargar más';

    // Cancel selection (UI-only): clears server-side pending_target_selection.
    // WHY: Users need a one-click escape hatch while browsing candidates.
    const cancelBtn = document.createElement('button');
    cancelBtn.type = 'button';
    cancelBtn.className = 'button button-secondary';
    cancelBtn.textContent = 'Cancelar';

    actions.appendChild(pickBtn);
    actions.appendChild(loadMoreBtn);
    actions.appendChild(cancelBtn);
    card.appendChild(actions);

    const hint = document.createElement('div');
    hint.className = 'apai-target-hint';
    hint.textContent = 'Si preferís, también podés escribir el ID/SKU en el chat.';
    card.appendChild(hint);

    function renderMeta(){
        const shown = loaded.length;
        if(totalCount && totalCount > 0){
            // UX: only say "podés seguir cargando" when there are more results.
            if(shown < totalCount){
                meta.textContent = 'Mostrando ' + shown + ' de ' + totalCount + ' (podés seguir cargando).';
                loadMoreBtn.style.display = '';
            }else{
                meta.textContent = 'Mostrando ' + shown + ' de ' + totalCount + '.';
                loadMoreBtn.style.display = 'none';
            }
        }else{
            meta.textContent = shown > 0 ? ('Mostrando ' + shown + ' resultados.') : 'No hay resultados todavía.';
            loadMoreBtn.style.display = (shown >= pageLimit) ? '' : 'none';
        }
    }

    function renderList(){
        list.innerHTML = '';
        loaded.forEach((c) => {
            if(!c || !c.id) return;

            const row = document.createElement('label');
            row.className = 'apai-target-row';

            const box = document.createElement('input');
            box.type = 'checkbox';
            box.className = 'apai-target-check';
            box.checked = (selectedId !== null && String(selectedId) === String(c.id));

            box.addEventListener('change', () => {
                // Single selection semantics: only one checked.
                if(box.checked){
                    selectedId = c.id;
                    // uncheck others
                    const all = list.querySelectorAll('input.apai-target-check');
                    all.forEach(el => {
                        if(el !== box){ el.checked = false; }
                    });
                    pickBtn.disabled = false;
                }else{
                    selectedId = null;
                    pickBtn.disabled = true;
                }
            });

            const txt = document.createElement('div');
            txt.className = 'apai-target-text';

            // Thumbnail (UI-only): helps disambiguate visually in large catalogs.
            const thumb = document.createElement('div');
            thumb.className = 'apai-target-thumb';
            if(c.thumb_url){
                try{
                    thumb.style.backgroundImage = 'url(' + c.thumb_url + ')';
                    thumb.classList.add('has-img');
                }catch(e){}
            }

            const main = document.createElement('div');
            main.className = 'apai-target-title';
            main.textContent = (c.title || ('Producto #' + c.id));

            const sub = document.createElement('div');
            sub.className = 'apai-target-sub';
            // Badges: ID / SKU / Precio
            const b1 = document.createElement('span');
            b1.className = 'apai-badge';
            b1.textContent = 'ID: ' + c.id;
            sub.appendChild(b1);

            if(c.sku){
                const b2 = document.createElement('span');
                b2.className = 'apai-badge';
                b2.textContent = 'SKU: ' + c.sku;
                sub.appendChild(b2);
            }
            if(c.price !== undefined && c.price !== null && String(c.price) !== ''){
                const b3 = document.createElement('span');
                b3.className = 'apai-badge';
                b3.textContent = 'Precio: ' + c.price;
                sub.appendChild(b3);
            }

            // Categories (UI-only)
            if(Array.isArray(c.categories) && c.categories.length){
                c.categories.slice(0, 2).forEach((cat) => {
                    const b = document.createElement('span');
                    b.className = 'apai-badge apai-badge-cat';
                    b.textContent = String(cat);
                    sub.appendChild(b);
                });
            }

            txt.appendChild(main);
            txt.appendChild(sub);

            row.appendChild(box);
            row.appendChild(thumb);
            row.appendChild(txt);
            list.appendChild(row);
        });
        renderMeta();
    }

    async function fetchPage(opts){
        const q = (opts && typeof opts.q === 'string') ? opts.q : currentQuery;
        const offset = (opts && typeof opts.offset === 'number') ? opts.offset : loaded.length;
        const limit = (opts && typeof opts.limit === 'number') ? opts.limit : pageLimit;

        // REST endpoint registered by the Brain.
        if(!APAI_AGENT_DATA || !APAI_AGENT_DATA.product_search_url){
            return { ok:false, items:[], total:0 };
        }

        const url = new URL(APAI_AGENT_DATA.product_search_url, window.location.origin);
        url.searchParams.set('q', q);
        url.searchParams.set('offset', String(offset));
        url.searchParams.set('limit', String(limit));

        const res = await fetch(url.toString(), {
            method: 'GET',
            headers: {
                'X-WP-Nonce': APAI_AGENT_DATA.nonce,
            },
        });
        return await res.json();
    }

    async function runSearch(reset){
        const q = (searchInput.value || '').toString().trim();
        currentQuery = q;
        if(reset){
            loaded = [];
            selectedId = null;
            pickBtn.disabled = true;
        }
        try{
            searchBtn.disabled = true;
            loadMoreBtn.disabled = true;

            const data = await fetchPage({ q: currentQuery, offset: reset ? 0 : loaded.length, limit: pageLimit });
            if(data && data.ok){
                const items = Array.isArray(data.items) ? data.items : [];
                if(typeof data.total === 'number'){
                    totalCount = data.total;
                }
                // Normalize
                // Keep UI-only fields for a richer selector (thumbnail + categories)
                // without changing backend logic.
                const norm = items.map(it => ({
                    id: it.id,
                    title: it.title,
                    sku: it.sku,
                    price: it.price,
                    thumb_url: it.thumb_url,
                    categories: it.categories,
                })).filter(it => it && it.id);

                loaded = reset ? norm : loaded.concat(norm);
                renderList();
            }
        }catch(e){
            // keep silent
        }finally{
            searchBtn.disabled = false;
            loadMoreBtn.disabled = false;
        }
    }

    // Initial render from server candidates (first page)
    loaded = initialCandidates.map(it => ({
        id: it.id,
        title: it.title,
        sku: it.sku,
        price: it.price,
        thumb_url: it.thumb_url,
        categories: it.categories,
    })).filter(it => it && it.id);
    renderList();

    searchBtn.addEventListener('click', (e) => {
        e.preventDefault();
        runSearch(true);
    });
    searchInput.addEventListener('keydown', (e) => {
        if(e.key === 'Enter'){
            e.preventDefault();
            runSearch(true);
        }
    });
    loadMoreBtn.addEventListener('click', (e) => {
        e.preventDefault();
        runSearch(false);
    });
    cancelBtn.addEventListener('click', (e) => {
        e.preventDefault();
        // Backend already knows how to cancel this sub-flow via text token.
        // We keep it silent to avoid cluttering the chat with UI clicks.
        try{ cancelBtn.disabled = true; }catch(err){}
        UI.sendMessage('cancelar', { silentUser: true });
    });
    pickBtn.addEventListener('click', (e) => {
        e.preventDefault();
        if(!selectedId) return;
        UI.sendMessage('ID ' + selectedId, { silentUser: true });
    });

    container.appendChild(card);
    UI.coreui.scrollMessagesToBottom(true);
}


    
    function closeAllActionCardsBySummary(summary, label){
        try{
            const cards = document.querySelectorAll('.apai-agent-action-card');
            cards.forEach(c => {
                const s = c.querySelector('.apai-agent-action-summary');
                const st = s ? String(s.textContent || '').trim() : '';
                const target = summary ? String(summary).trim() : '';
                if(!target || st === target){
                    closeActionCard(c, label);
                }
            });
        }catch(e){}
    }

	function normalizeActionForUI(action){
		// Some server paths wrap pending_action as an envelope: { type, action:{...}, created_at }
		if(action && typeof action === 'object' && action.action && typeof action.action === 'object'){
			return action.action;
		}
		return action;
	}


    async function fetchProductSummary(productId){
        try{
            if(!productId) return null;
            if(!window.APAI_AGENT_DATA || !APAI_AGENT_DATA.product_summary_url) return null;
            const url = new URL(APAI_AGENT_DATA.product_summary_url, window.location.origin);
            url.searchParams.set('id', String(productId));
            const res = await fetch(url.toString(), {
                method: 'GET',
                headers: { 'X-WP-Nonce': APAI_AGENT_DATA.nonce }
            });
            const data = await res.json();
            if(data && data.ok && data.product){
                return data.product;
            }
        }catch(e){}
        return null;
    }

	// If a response proposed an action but did not include store_state.pending_action
	// (edge paths / caching), fetch the server-truth snapshot (lite) and render from it.
	// @INVARIANT: buttons are shown ONLY when the server says pending_action != null.
	// Keep the pending action card stable in the chat.
	// Important: do NOT re-render (or move) the same pending card on every response (A1–A8),
	// otherwise it jumps to the bottom and looks like it belongs to the last message.
	function ensurePendingCardFromServerTruth(payload){
		try{
			const pa = (payload && payload.store_state) ? payload.store_state.pending_action : null;
			const keyNow = apaiPendingKey(pa);
			const modeNow = (payload && payload.meta && payload.meta.pending_choice) ? 'choice' : 'pending';
			const existing = document.querySelector('.apai-agent-action-card');

			if(!pa){
            // No pending: if the server explicitly cleared a pending action (e.g. user cancelled via text),
            // keep the latest action card in the timeline by closing it instead of removing it.
            try{
                const lbl = (payload && payload.meta && payload.meta.pending_cleared_label) ? String(payload.meta.pending_cleared_label) : '';
                if(lbl){
                    const cards = document.querySelectorAll('.apai-agent-action-card');
                    cards.forEach((card)=>{
                        try{
                            const st = card && card.dataset ? String(card.dataset.apaiState || '') : '';
                            if(st === 'closed'){ return; }
                            if(UI && UI.coreui && typeof UI.coreui.closeActionCard === 'function'){
                                UI.coreui.closeActionCard(card, lbl);
                            }
                        }catch(e){}
                    });
                }
            }catch(e){}
            // No pending: clear any remaining open cards and reset key
            try{ removeAllActionCards(); }catch(e){}
            try{ session_state.last_pending_action_key = ''; UI.coreui.saveSessionState(session_state); }catch(e){}
            return;
        }

			// If we already rendered this same pending action in the same mode, keep the card where it is.
			// If the mode changed (eg: pending -> choice), re-render to show the right buttons.
			if(existing && window.__apai_last_pending_key && window.__apai_last_pending_key === keyNow){
				const lastMode = window.__apai_last_pending_mode || 'pending';
				if(lastMode === modeNow){
					return;
				}
			}

			// Render / update
			try{ removeAllActionCards(); }catch(e){}

			// Fallback para payloads "lite": si pending_action no trae `action`, construimos uno mínimo
			// para poder mostrar siempre la tarjeta con botones.
			if(pa && !pa.action){
				try{
					const lp = (payload && payload.last_product) ? payload.last_product : null;
					const lpName = (lp && (lp.name || lp.title)) ? (lp.name || lp.title) : '';
					const pid = (pa.summary && pa.summary.product_id) ? pa.summary.product_id : (pa.product_id || null);
					let summaryTxt = 'Acción pendiente.';
					if(pa.type === 'update_product'){
						summaryTxt = 'Tenés una acción pendiente de modificación' + (lpName ? (" ("+lpName+")") : (pid ? (" (ID "+pid+")") : '')) + '.';
					}
					pa.action = {
						type: pa.type || (pa.summary ? pa.summary.type : 'action'),
						human_summary: summaryTxt,
						product_id: pid || undefined
					};
				}catch(e){}
			}
			addActionCard(pa.action, null, Object.assign({ source:'pending_payload' }, (payload && payload.meta) ? { pending_choice: payload.meta.pending_choice, deferred_message: payload.meta.deferred_message } : {}));
			try{ window.__apai_last_pending_key = keyNow; }catch(e){}
			try{ window.__apai_last_pending_mode = modeNow; }catch(e){}
		}catch(e){}
	}

	function addActionCard(action, uiLabels, meta){
	    const container = document.getElementById('apai_agent_messages');
	    if(!container || !action) return;
		    action = normalizeActionForUI(action);
		    if(!action) return;



        const card = document.createElement('div');
        card.className = 'apai-agent-action-card';
        try{ card.dataset.apaiState = 'active'; }catch(e){}

        const title = document.createElement('div');
        title.className = 'apai-agent-action-title';
        title.textContent = 'Acción propuesta por el agente';

        const summary = document.createElement('div');
        summary.className = 'apai-agent-action-summary';
		    summary.textContent = action.human_summary || 'Cambiar un producto en el catálogo.';

        // Product preview (thumbnail/title/price/categories) — UI-only.
        const productPreview = document.createElement('div');
        productPreview.className = 'apai-action-product';
        productPreview.style.display = 'none';

        const pid = action.product_id || action.target_product_id || action.target_id || action.productId || null;
        if(pid){
            productPreview.style.display = '';
            productPreview.innerHTML = '' +
              '<div class="apai-action-thumb" aria-hidden="true"></div>' +
              '<div class="apai-action-meta">' +
                '<div class="apai-action-name">Producto #' + pid + '</div>' +
                '<div class="apai-action-sub">Cargando detalles…</div>' +
                '<div class="apai-action-cats"></div>' +
              '</div>';

            // Fill async (does not affect action logic).
            fetchProductSummary(pid).then((p) => {
                if(!p) return;
                try{
                    const thumb = productPreview.querySelector('.apai-action-thumb');
                    const name = productPreview.querySelector('.apai-action-name');
                    const sub = productPreview.querySelector('.apai-action-sub');
                    const cats = productPreview.querySelector('.apai-action-cats');

                    if(name) name.textContent = p.title || p.name || ('Producto #' + pid);
                    const fmtPrice = (v) => {
                        try {
                            if (UI && UI.utils && typeof UI.utils.formatPrice === 'function') {
                                return UI.utils.formatPrice(v);
                            }
                        } catch(e){}
                        return (v == null) ? '' : String(v);
                    };

                    const pieces = [];
                    if(p.id) pieces.push('ID ' + p.id);

                    // Price: show proposed change if present (avoid preview "desfasado")
                    const currentPriceTxt = fmtPrice(p.price);
                    let proposedPriceRaw = null;
                    try {
                        if (action && action.changes) {
                            if (action.changes.regular_price != null) proposedPriceRaw = action.changes.regular_price;
                            else if (action.changes.price != null) proposedPriceRaw = action.changes.price;
                        }
                    } catch(e){}
                    const proposedPriceTxt = proposedPriceRaw != null ? fmtPrice(proposedPriceRaw) : '';
                    let pricePart = currentPriceTxt;
                    if (proposedPriceTxt) {
                        pricePart = (currentPriceTxt && currentPriceTxt !== proposedPriceTxt)
                            ? (currentPriceTxt + ' → ' + proposedPriceTxt)
                            : proposedPriceTxt;
                    }
                    if(pricePart) pieces.push(pricePart);

                    // Stock: show proposed change if present
                    let curStock = null;
                    try {
                        if (typeof p.stock_quantity !== 'undefined') curStock = p.stock_quantity;
                        else if (typeof p.stock !== 'undefined') curStock = p.stock;
                    } catch(e){}
                    let newStock = null;
                    try {
                        if (action && action.changes && action.changes.stock_quantity != null) newStock = action.changes.stock_quantity;
                    } catch(e){}
                    if (newStock != null) {
                        const stockPart = (curStock != null && curStock !== '')
                            ? ('Stock ' + curStock + ' → ' + newStock)
                            : ('Stock ' + newStock);
                        pieces.push(stockPart);
                    }

                    if(sub) sub.textContent = pieces.join(' · ') || '';

                    if(thumb){
                        if(p.thumb_url){
                            thumb.style.backgroundImage = 'url(' + p.thumb_url + ')';
                            thumb.classList.add('has-img');
                        }else{
                            thumb.classList.remove('has-img');
                        }
                    }

                    if(cats){
                        cats.innerHTML = '';
                        const arr = Array.isArray(p.categories) ? p.categories : [];
                        arr.slice(0, 3).forEach((c) => {
                            const chip = document.createElement('span');
                            chip.className = 'apai-chip';
                            chip.textContent = String(c);
                            cats.appendChild(chip);
                        });
                    }
                }catch(e){}
            }).catch(()=>{});
        }


		// If backend asks for a pending choice (keep pending vs swap to new), render a 2-button choice card.
		if(meta && meta.pending_choice && String(meta.pending_choice) === 'swap_to_deferred'){
			const btnRow = document.createElement('div');
			btnRow.className = 'apai-agent-action-buttons';

			const keepBtn = document.createElement('button');
			keepBtn.className = 'button button-secondary';
			keepBtn.textContent = 'Seguir con la pendiente';
			keepBtn.dataset.apaiAction = 'pending_keep';
			keepBtn.addEventListener('click', function(){
				// Replace this choice UI with the normal pending card (Confirmar/Cancelar).
				try{
					closeAllActionCardsBySummary(action.human_summary || '', null);
				}catch(e){}
				// Force render pending card in normal mode.
				try{ window.__apai_last_pending_mode = ''; }catch(e){}
				ensurePendingCardFromServerTruth({ store_state: { pending_action: { type: action.type || 'update_product', action: action } }, meta: {} });
			});

			const swapBtn = document.createElement('button');
			swapBtn.className = 'button button-primary';
				swapBtn.textContent = 'Reemplazar por la nueva';
			swapBtn.dataset.apaiAction = 'pending_swap';
				swapBtn.addEventListener('click', function(){
					// Cancel current pending and replay the deferred user message to build the new action.
					const deferred = meta && meta.deferred_message ? String(meta.deferred_message) : '';
					// Keep cancel deterministic (backend understands "cancelar").
					const cancelMsg = 'cancelar';

					UI.sendMessage(cancelMsg, { silentUser: true, replayAfter: deferred, replaySilentUser: true, pendingChoice: true });
				});

			btnRow.appendChild(keepBtn);
			btnRow.appendChild(swapBtn);

			card.appendChild(title);
			card.appendChild(summary);
			card.appendChild(btnRow);

			// Importante: esta tarjeta se usa cuando hay una acción pendiente y el usuario pidió otra.
			// Debe renderizarse en el chat (antes se retornaba sin insertarla y desaparecían los botones).
			container.appendChild(card);
			UI.coreui.scrollMessagesToBottom();
			return card;
		}

		const btn = document.createElement('button');
		btn.className = 'button button-secondary';
	        btn.classList.add('apai-confirm-btn');
		const defaultConfirmLabel = 'Confirmar y ejecutar acción';
		const confirmLabel = (uiLabels && uiLabels.confirm) ? String(uiLabels.confirm) : defaultConfirmLabel;
		btn.textContent = confirmLabel;
		btn.dataset.apaiAction = 'confirm';
		btn.addEventListener('click', function(){
            // Brain owns the chat; execution depends on selected executor agent.
            const selector = document.getElementById('apai_agent_selector');
            const selected = selector ? selector.value : 'catalog';
            if(selected === 'catalog' && !APAI_AGENT_DATA.has_cat_agent){
                UI.coreui.addMessage('assistant', 'Todavía no puedo ejecutar porque el Agente de Catálogo no está activo. Si lo activás, lo hacemos enseguida 😊');
                return;
            }

	            // Guard against fast double-clicks / confirm+cancel races.
	            if(card.dataset.apaiLocked === '1') return;
	            if(!__apaiActionLock.lock(action.human_summary || 'confirm')) return;
	            UI.coreui.lockActionCard(card, 'confirm');
            // Block chat input until the brain clears pending server-side.
            try{
                const inEl = document.getElementById('apai_agent_input');
                const sb = document.getElementById('apai_agent_send');
                if(inEl) inEl.disabled = true;
                if(sb) sb.disabled = true;
            }catch(e){}

            fetch(APAI_AGENT_DATA.execute_url, {
                method: 'POST',
                headers: {
                    'X-WP-Nonce': APAI_AGENT_DATA.nonce,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ action: action, session_state: loadSessionState() })
            })
	        .then(r => r.json().then(data => ({ data, headers: r.headers })))
	        .then(({ data, headers }) => {
                if(!data.ok){
                    const msg = data.message || data.error || data.code || 'Error al ejecutar la acción.';
                    // NOOP friendly: si no hay cambios para aplicar, tratamos como éxito y limpiamos pending.
                    if(typeof msg === 'string' && msg.toLowerCase().includes('no hay cambios para aplicar')){
						// Hide action buttons for the *clicked* card even if the summary text mismatches.
						try{ if(typeof closeActionCard === 'function') closeActionCard(card, '✅ Sin cambios.'); }catch(e){}
						// Back-compat fallback (closes other open cards if any).
						closeAllActionCardsBySummary(action.human_summary || '', '✅ Sin cambios.');
                        UI.coreui.addMessage('assistant', 'Listo ✅ No había cambios para aplicar (ya estaba así).');
                        try{
                            if(APAI_AGENT_DATA.clear_pending_url){
                                fetch(APAI_AGENT_DATA.clear_pending_url, {
                                    method: 'POST',
                                    headers: {
                                        'X-WP-Nonce': APAI_AGENT_DATA.nonce,
                                        'Content-Type': 'application/json'
                                    },
                                    body: JSON.stringify({ executed: true, summary: (action && action.human_summary) ? action.human_summary : '', ts: Date.now(), noop: true })
                                }).catch(()=>{});
                            }
                        }catch(e){}
						// unlock UI
						__apaiActionLock.unlock();
						UI.coreui.unlockActionCard(card);
						try{
							const inEl = document.getElementById('apai_agent_input');
							const sb = document.getElementById('apai_agent_send');
							if(inEl) inEl.disabled = false;
							if(sb) sb.disabled = false;
						}catch(e){}
						try{
							btn.disabled = false;
							btn.textContent = 'Confirmar y ejecutar acción';
						}catch(e){}
                        return;
                    }
                    UI.coreui.addMessage('assistant', 'Error al ejecutar la acción: ' + msg);
                    console.error(data);
                    try{
                        const inEl = document.getElementById('apai_agent_input');
                        const sb = document.getElementById('apai_agent_send');
                        if(inEl) inEl.disabled = false;
                        if(sb) sb.disabled = false;
                    }catch(e){}
	                    // unlock UI
	                    __apaiActionLock.unlock();
	                    UI.coreui.unlockActionCard(card);
	                    btn.disabled = false;
	                    btn.textContent = 'Intentar de nuevo';
                    return;
                }
	                // Cerrar UI post-ejecución: Confirmar/Cancelar deben desaparecer siempre.
	                // Importante: cerramos SIEMPRE la card clickeada, aunque el texto del summary no coincida.
	                try{ if(typeof closeActionCard === 'function') closeActionCard(card, '✅ Ejecutada'); }catch(e){}
						// Back-compat fallback (closes other open cards if any).
						closeAllActionCardsBySummary(action.human_summary || '', '✅ Acción ejecutada.');
                try{
                    // If executor returned product info, store it as last_product
                    const prod = (data && data.product) ? data.product : ((data && data.data && data.data.product) ? data.data.product : null);
                    if(prod && prod.id){ setLastProduct(prod); }
                }catch(e){}
                // Persist a stable badge-style message in the chat history.
                let execMsg = data.message || ('Acción ejecutada correctamente: ' + (action && action.human_summary ? action.human_summary : ''));
                if(typeof execMsg === 'string'){
                    execMsg = execMsg.trim();
                    if(execMsg && !execMsg.startsWith('✅')){
                        execMsg = '✅ ' + execMsg;
                    }
                }
                UI.coreui.addMessage('assistant', execMsg || '✅ Acción ejecutada.');
                // Nota: el debug se refresca DESPUÉS de clear_pending (para evitar pending stale en Lite).

                // Limpieza fuerte post-ejecución (PASO 2): limpiar pending_action del lado Brain.
                // Strong server-side cleanup (PASO 2): clear pending_action in Brain.
                // We await this before unblocking chat input to avoid "hola" seeing stale pending.
                let clearPromise = Promise.resolve();
                try{
                    if(APAI_AGENT_DATA.clear_pending_url){
                        clearPromise = fetch(APAI_AGENT_DATA.clear_pending_url, {
                            method: 'POST',
                            headers: {
                                'X-WP-Nonce': APAI_AGENT_DATA.nonce,
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify({ executed: true, summary: (action && action.human_summary) ? action.human_summary : '', ts: Date.now() })
                        }).then(()=>{}).catch(()=>{});
                    }
                }catch(e){}

                clearPromise.finally(() => {
                    try{
                        // Refrescar debug LITE luego de clear_pending para evitar mostrar pending stale.
                        try{ fetchDebug(); }catch(e){}
                        const inEl = document.getElementById('apai_agent_input');
                        const sb = document.getElementById('apai_agent_send');
                        if(inEl) inEl.disabled = false;
                        if(sb) sb.disabled = false;
                    }catch(e){}
	                    // unlock global action guard
	                    __apaiActionLock.unlock();
	                    UI.coreui.unlockActionCard(card);
                });

                // pending_action es 100% server-side (PASO 2). La UI no mantiene estado.
            })
            .catch(err => {
                console.error(err);
                try{
                    const inEl = document.getElementById('apai_agent_input');
                    const sb = document.getElementById('apai_agent_send');
                    if(inEl) inEl.disabled = false;
                    if(sb) sb.disabled = false;
                }catch(e){}
	                // unlock UI
	                __apaiActionLock.unlock();
	                UI.coreui.unlockActionCard(card);
                btn.disabled = false;
                btn.textContent = 'Error';
                UI.coreui.addMessage('assistant', 'Error de red al ejecutar la acción.');
            });
        });

        
        const cancelBtn = document.createElement('button');
        cancelBtn.className = 'button button-secondary';
	        cancelBtn.classList.add('apai-cancel-btn');
        const defaultCancelLabel = 'Cancelar';
        const cancelLabel = (uiLabels && uiLabels.cancel) ? String(uiLabels.cancel) : defaultCancelLabel;
        cancelBtn.textContent = cancelLabel;
        cancelBtn.dataset.apaiAction = 'cancel';
        cancelBtn.style.marginLeft = '8px';
        cancelBtn.addEventListener('click', function(){
	            // Guard against fast confirm+cancel races.
	            if(card.dataset.apaiLocked === '1') return;
	            if(!__apaiActionLock.lock(action.human_summary || 'cancel')) return;
	            UI.coreui.lockActionCard(card, 'cancel');
	
	            const pendingChoice = (meta && meta.pending_choice) ? String(meta.pending_choice) : '';
	            const deferred = (meta && meta.deferred_message) ? String(meta.deferred_message) : '';
	
	            // IMPORTANT: do not close the action card optimistically.
	            // We wait for server truth to avoid contradictory UI when requests race.
	            try{ window.__apaiCancelInFlight = true; }catch(e){}
	            try{
	                const inEl = document.getElementById('apai_agent_input');
	                const sb = document.getElementById('apai_agent_send');
	                if(inEl) inEl.disabled = true;
	                if(sb) sb.disabled = true;
	            }catch(e){}

	            // IMPORTANT (@INVARIANT): use a deterministic cancel keyword.
	            // "cancelar y continuar" was too fragile and could be ignored by the backend,
	            // leaving the UI seemingly stuck with no feedback.
	            const cancelMsg = 'cancelar';

            // Do NOT hide assistant output: the user needs visible confirmation.
            UI.sendMessage(cancelMsg, { silentUser: true, replayAfter: deferred, replaySilentUser: true })
	                .then((data) => {
	                    // Only show the cancelled label if the server actually cleared pending.
	                    try{
	                        const st = (data && data.store_state) ? data.store_state : ((data && data.context && data.context.store_state) ? data.context.store_state : null);
	                        if((action.human_summary || '') && st && (st.pending_action === null || typeof st.pending_action === 'undefined')){
	                            // Hide action buttons for the *clicked* card even if the summary text mismatches.
	                            try{ if(typeof closeActionCard === 'function') closeActionCard(card, '❌ Cancelada'); }catch(e){}
	                            // Back-compat fallback (closes other open cards if any).
	                            closeAllActionCardsBySummary(action.human_summary || '', '❌ Cancelada');
	
	                            // Fallback replay: some backend paths may not set meta.should_clear_pending.
	                            // If pending is now cleared and we have a deferred command, replay it.
	                            const replay = deferred ? String(deferred) : '';
	                            if(replay){
	                                if(!window.__apaiReplayLock){ window.__apaiReplayLock = {}; }
	                                if(!window.__apaiReplayLock[replay]){
	                                    window.__apaiReplayLock[replay] = true;
	                                    setTimeout(() => UI.sendMessage(replay, { silentUser: true }), 180);
	                                }
	                            }
	                        }
	                    }catch(e){}
	                })
	                .finally(() => {
	                    try{ window.__apaiCancelInFlight = false; }catch(e){}
	                    try{
	                        const inEl2 = document.getElementById('apai_agent_input');
	                        const sb2 = document.getElementById('apai_agent_send');
	                        if(inEl2) inEl2.disabled = false;
	                        if(sb2) sb2.disabled = false;
	                    }catch(e){}
	                    __apaiActionLock.unlock();
	                    UI.coreui.unlockActionCard(card);
	                    try{
	                        const q = window.__apaiQueuedMsg;
	                        window.__apaiQueuedMsg = null;
	                        if(q && q.msg){ setTimeout(() => UI.sendMessage(q.msg, q.opts || {}), 120); }
	                    }catch(e){}
	                });
        });

card.appendChild(title);
        try{ if(productPreview) card.appendChild(productPreview); }catch(e){}
        card.appendChild(summary);

        // Mini tabla de variaciones (si aplica)
        if(action.ui_preview_table && action.ui_preview_table.headers && action.ui_preview_table.rows){
            const tblWrap = document.createElement('div');
            tblWrap.className = 'apai-agent-variation-preview';

            const tblTitle = document.createElement('div');
            tblTitle.className = 'apai-agent-variation-title';
            tblTitle.textContent = 'Variaciones y precios (preview)';
            tblWrap.appendChild(tblTitle);

            const table = document.createElement('table');
            table.className = 'apai-agent-variation-table';

            const thead = document.createElement('thead');
            const trh = document.createElement('tr');
            (action.ui_preview_table.headers || []).forEach(h => {
                const th = document.createElement('th');
                th.textContent = h;
                trh.appendChild(th);
            });
            const thp = document.createElement('th');
            thp.textContent = 'Precio';
            trh.appendChild(thp);
            thead.appendChild(trh);
            table.appendChild(thead);

            const tbody = document.createElement('tbody');
            (action.ui_preview_table.rows || []).forEach(r => {
                const tr = document.createElement('tr');
                const attrs = (r && r.attrs) ? r.attrs : {};
                (action.ui_preview_table.headers || []).forEach(h => {
                    const td = document.createElement('td');
                    td.textContent = (attrs[h] !== undefined) ? String(attrs[h]) : '';
                    tr.appendChild(td);
                });
                const tdp = document.createElement('td');
                const n = (r && r.price !== undefined) ? Number(r.price) : 0;
                // mostrar sin decimales si es entero
                const isInt = Number.isFinite(n) && Math.abs(n - Math.round(n)) < 1e-9;
                tdp.textContent = Number.isFinite(n) ? ('$' + (isInt ? Math.round(n) : n.toFixed(2))) : '';
                tr.appendChild(tdp);
                tbody.appendChild(tr);
            });
            table.appendChild(tbody);
            tblWrap.appendChild(table);

            if(action.ui_preview_table.truncated){
                const note = document.createElement('div');
                note.className = 'apai-agent-variation-note';
                note.textContent = 'Mostrando ' + (action.ui_preview_table.rows || []).length + ' de ' + action.ui_preview_table.total + ' variaciones.';
                tblWrap.appendChild(note);
            }

            card.appendChild(tblWrap);
        }

        card.appendChild(btn);
        card.appendChild(cancelBtn);
        container.appendChild(card);
        UI.coreui.scrollMessagesToBottom(true);
    }


    // Exports
    UI.corecards.ensurePendingCardFromServerTruth = ensurePendingCardFromServerTruth;
    UI.corecards.ensureTargetSelectionCardFromServerTruth = ensureTargetSelectionCardFromServerTruth;
    UI.corecards.addActionCard = addActionCard;
    UI.corecards.addTargetSelectionCard = addTargetSelectionCard;

    UI.corecards.closeAllActionCards = closeAllActionCards;
    UI.corecards.closeAllTargetSelectionCards = closeAllTargetSelectionCards;
    UI.corecards.removeAllActionCards = removeAllActionCards;
    UI.corecards.removeAllTargetSelectionCards = removeAllTargetSelectionCards;
})();
