(function(){
  'use strict';

  window.APAI_AGENT_UI = window.APAI_AGENT_UI || {};
  const UI = window.APAI_AGENT_UI;
  UI.typing = UI.typing || {};

  function makeWrap(){
    const wrap = document.createElement('div');
    wrap.className = 'apai-agent-message apai-agent-assistant apai-typing-wrap';
    const bubble = document.createElement('div');
    bubble.className = 'apai-agent-bubble apai-typing-bubble';
    const dots = document.createElement('div');
    dots.className = 'apai-typing-dots';
    dots.innerHTML = '<span></span><span></span><span></span>';
    bubble.appendChild(dots);
    wrap.appendChild(bubble);
    return wrap;
  }

  UI.typing.show = function(container){
    try{
      const root = container || document.getElementById('apai_agent_messages');
      if(!root) return null;
      const wrap = makeWrap();
      root.appendChild(wrap);
      return wrap;
    }catch(e){ return null; }
  };

  UI.typing.remove = function(node){
    if(!node) return;
    try{ node.remove(); }catch(e){ if(node.parentNode){ node.parentNode.removeChild(node); } }
  };

  // Typewriter effect (fast & smooth). Writes in chunks for performance.
  UI.typing.typewriter = function(targetEl, text, opts){
    opts = opts || {};
    const full = (text || '').toString();
    const minTick = UI.utils ? UI.utils.clamp(opts.minTick || 12, 6, 40) : 12;
    const maxTick = UI.utils ? UI.utils.clamp(opts.maxTick || 22, 10, 60) : 22;

    // chunk size adapts to length
    const len = full.length;
    const chunk = len > 1200 ? 6 : (len > 400 ? 4 : 2);
    let i = 0;

    return new Promise(function(resolve){
      if(!targetEl){ resolve(); return; }

      function step(){
        // user might navigate away
        if(!targetEl.isConnected){ resolve(); return; }

        i = Math.min(len, i + chunk);
        targetEl.textContent = full.slice(0, i);

        if(i >= len){
          resolve();
          return;
        }

        const prog = i / Math.max(1, len);
        const tick = Math.round(maxTick - (maxTick - minTick) * (0.6 + 0.4 * prog));
        setTimeout(step, tick);
      }

      // start
      targetEl.textContent = '';
      step();
    });
  };

})();