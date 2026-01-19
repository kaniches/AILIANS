(function(){
  'use strict';

  window.APAI_AGENT_UI = window.APAI_AGENT_UI || {};
  const UI = window.APAI_AGENT_UI;
  UI.layout = UI.layout || {};

  // Determine which element actually scrolls.
  // Our UI uses a fixed `.apai-shell` with `overflow-y:auto`, so `window` may not scroll.
  function findScrollContainer(startEl){
    let el = startEl;
    while(el && el !== document.body && el !== document.documentElement){
      try{
        const cs = window.getComputedStyle(el);
        const oy = (cs && cs.overflowY) ? cs.overflowY : '';
        if((oy === 'auto' || oy === 'scroll') && el.scrollHeight > el.clientHeight + 1){
          return el;
        }
      }catch(e){}
      el = el.parentElement;
    }
    return document.scrollingElement || document.documentElement;
  }

  function getScrollMetrics(container){
    if(container === document.scrollingElement || container === document.documentElement){
      const doc = document.documentElement;
      const scrollTop = window.pageYOffset || doc.scrollTop || 0;
      const clientHeight = window.innerHeight || doc.clientHeight || 0;
      const scrollHeight = doc.scrollHeight || 0;
      return { scrollTop, clientHeight, scrollHeight };
    }
    return { scrollTop: container.scrollTop, clientHeight: container.clientHeight, scrollHeight: container.scrollHeight };
  }

  function scrollToBottom(container, smooth){
    const m = getScrollMetrics(container);
    const top = Math.max(0, m.scrollHeight - m.clientHeight);
    const behavior = smooth ? 'smooth' : 'auto';
    if(container === document.scrollingElement || container === document.documentElement){
      window.scrollTo({ top, behavior });
    }else{
      try{ container.scrollTo({ top, behavior }); }
      catch(e){ container.scrollTop = top; }
    }
  }

  UI.layout.getChatEl = function(){ return document.getElementById('apai_agent_chat'); };
  UI.layout.getMessagesEl = function(){ return document.getElementById('apai_agent_messages'); };

  // Fit the chat frame to the first viewport.
  // ChatGPT behavior: the page itself should not require scrolling to use the chat;
  // only the messages area scrolls.
  UI.layout.fitViewport = function(){
    const chat = UI.layout.getChatEl();
    if(!chat) return;
    try{
      const rect = chat.getBoundingClientRect();
      const pad = 12;
      const h = Math.max(520, Math.floor(window.innerHeight - rect.top - pad));
      chat.style.height = h + 'px';
      chat.style.minHeight = h + 'px';
    }catch(e){}
  };

  UI.layout.autoGrow = function(textarea){
    try{
      if(!textarea) return;
      textarea.style.height = 'auto';
      const h = Math.min(textarea.scrollHeight, 160);
      textarea.style.height = h + 'px';
    }catch(e){}
  };

  // ChatGPT-like autoscroll inside the messages container.
  // - If the user is near the bottom, keep pinned.
  // - If the user scrolls up, do not yank them.
  // - IMPORTANT: do not force scroll on initial load.
  UI.layout.createAutoScroller = function(){
    const msgEl = UI.layout.getMessagesEl();
    if(!msgEl) return { scroll: function(){} };

    // Real scroll container (usually `.apai-shell`).
        // Prefer the shell if present (CSS/markup changes may confuse scroll detection).
    const shellEl = document.getElementById('apai_shell');
    const scrollEl = shellEl || findScrollContainer(msgEl);
    const isPageScroll = (scrollEl === document.scrollingElement || scrollEl === document.documentElement || scrollEl === document.body || scrollEl === window);

    let armed = false;
    function updateArmed(){
      try{ armed = UI.utils.isNearBottom(scrollEl, 480); }
      catch(e){ armed = true; }
    }

    function scrollNow(smooth){
      try{ UI.utils.scrollToBottom(scrollEl, !!smooth); }
      catch(e){}
      // Layout can settle after images / fonts; do a second pass.
      requestAnimationFrame(function(){
        try{ UI.utils.scrollToBottom(scrollEl, false); }catch(e){}
      });
    }

    updateArmed();

    // Track "pinned to bottom" state on the actual scroller.
    const onScroll = UI.utils.debounce(updateArmed, 50);
    if(isPageScroll){
      window.addEventListener('scroll', onScroll, { passive:true });
    }else{
      scrollEl.addEventListener('scroll', onScroll, { passive:true });
    }

    if(window.MutationObserver){
      try{
        const obs = new MutationObserver(function(){
          if(armed){ scrollNow(false); }
        });
        // subtree:true because messages often append nested nodes (cards, selectors, etc.)
        obs.observe(msgEl, { childList:true, subtree:true });
      }catch(e){}
    }

    return {
      scroll: function(force){
        if(force || armed){ scrollNow(false); }
      },
      arm: function(){ armed = true; },
      disarm: function(){ armed = false; }
    };
  };

})();
