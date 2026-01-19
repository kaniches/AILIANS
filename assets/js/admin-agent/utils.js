(function(){
  'use strict';

  window.APAI_AGENT_UI = window.APAI_AGENT_UI || {};
  const UI = window.APAI_AGENT_UI;

  UI.utils = UI.utils || {};

  UI.utils.qs = function(sel, root){
    return (root || document).querySelector(sel);
  };

  UI.utils.qsa = function(sel, root){
    return Array.prototype.slice.call((root || document).querySelectorAll(sel));
  };

  UI.utils.clamp = function(n, min, max){
    n = Number(n);
    if(!Number.isFinite(n)) n = min;
    return Math.max(min, Math.min(max, n));
  };

  UI.utils.debounce = function(fn, wait){
    let t = null;
    return function(){
      const args = arguments;
      clearTimeout(t);
      t = setTimeout(function(){ fn.apply(null, args); }, wait);
    };
  };

  function getScrollMetrics(target){
    // WP Admin can scroll on <html>, <body> or even a nested container.
    // To be resilient, treat window/document/html/body/scrollingElement as "page scroll".
    const isPage = (!target) || target === window || target === document || target === document.documentElement || target === document.body || target === document.scrollingElement;
    if(isPage){
      const scrollTop = (window.pageYOffset || document.documentElement.scrollTop || document.body.scrollTop || 0);
      const viewport = (window.innerHeight || document.documentElement.clientHeight || document.body.clientHeight || 0);
      const height = Math.max(document.documentElement.scrollHeight || 0, document.body.scrollHeight || 0);
      return { scrollTop, viewport, height, isPage: true };
    }
    return {
      scrollTop: target.scrollTop || 0,
      viewport: target.clientHeight || 0,
      height: target.scrollHeight || 0,
      isPage: false,
    };
  }

  // NOTE: The chat uses extra bottom padding so the sticky composer doesn't
  // cover the last message. That means the page can be "visually" at the
  // bottom while still being ~250-300px away from the true scroll bottom.
  // We therefore use a larger default threshold.
  UI.utils.isNearBottom = function(scrollEl, threshold){
    const t = (typeof threshold === 'number') ? threshold : 360;
    try{
      const m = getScrollMetrics(scrollEl);
      return (m.scrollTop + m.viewport) >= (m.height - t);
    }catch(e){
      return true;
    }
  };

  UI.utils.scrollToBottom = function(scrollEl, smooth){
    try{
      const m = getScrollMetrics(scrollEl);
      if(m.isPage){
        window.scrollTo({ top: m.height, behavior: smooth ? 'smooth' : 'auto' });
      }else{
        scrollEl.scrollTo({ top: m.height, behavior: smooth ? 'smooth' : 'auto' });
      }
    }catch(e){
      try{
        // Best-effort fallback
        window.scrollTo(0, Math.max(document.documentElement.scrollHeight || 0, document.body.scrollHeight || 0));
      }catch(e2){}
    }
  };

  UI.utils.formatPrice = function(p){
    if(p === undefined || p === null) return '';
    const s = String(p).trim();
    if(!s) return '';
    const n = Number(s);
    if(Number.isFinite(n)){
      const isInt = Math.abs(n - Math.round(n)) < 1e-9;
      return '$' + (isInt ? String(Math.round(n)) : n.toFixed(2));
    }
    return s;
  };

})();