/* global APAI_AGENT_DATA */

(function(){
  'use strict';

  function qs(sel){ return document.querySelector(sel); }

  function el(tag, cls){
    const e = document.createElement(tag);
    if(cls) e.className = cls;
    return e;
  }

  function buildMenu(){
    const menu = el('div','apai-plus-menu');
    menu.setAttribute('role','menu');
    menu.setAttribute('data-open','0');

    const items = [
      {
        key: 'price_list',
        title: 'Lista de precios',
        subtitle: 'Mostrar o consultar precios',
        icon: '💲',
        fill: 'Mostrame la lista de precios'
      },
      {
        key: 'suppliers',
        title: 'Proveedores',
        subtitle: 'Gestionar o consultar proveedores',
        icon: '🚚',
        fill: 'Mostrame los proveedores'
      }
    ];

    items.forEach((it) => {
      const btn = el('button','apai-plus-menu-item');
      btn.type = 'button';
      btn.setAttribute('role','menuitem');
      btn.dataset.key = it.key;

      const icon = el('span','apai-plus-menu-icon');
      icon.textContent = it.icon;

      const label = el('span','apai-plus-menu-label');
      const title = el('span','apai-plus-menu-title');
      title.textContent = it.title;
      const sub = el('span','apai-plus-menu-sub');
      sub.textContent = it.subtitle;
      label.appendChild(title);
      label.appendChild(sub);

      btn.appendChild(icon);
      btn.appendChild(label);
      btn.addEventListener('click', () => {
        try{
          const input = qs('#apai_agent_input');
          if(input){
            input.value = it.fill;
            input.focus();
            // Resize textarea if core attached autosize
            try{ input.dispatchEvent(new Event('input', { bubbles: true })); }catch(e){}
          }
        }catch(e){}
        closeMenu(menu);
      });

      menu.appendChild(btn);
    });

    document.body.appendChild(menu);
    return menu;
  }

  function isOpen(menu){ return menu && menu.getAttribute('data-open') === '1'; }

  function openMenu(menu, anchor){
    if(!menu || !anchor) return;
    menu.setAttribute('data-open','1');

    // Position: above the + button (ChatGPT-like).
    const r = anchor.getBoundingClientRect();
    const pad = 10;

    // Temporarily set visibility to measure.
    menu.style.left = '-9999px';
    menu.style.top = '-9999px';

    const mw = menu.offsetWidth || 220;
    const mh = menu.offsetHeight || 140;

    let left = r.left;
    let top = r.top - mh - pad;

    // Clamp inside viewport.
    const vw = window.innerWidth;
    const vh = window.innerHeight;

    if(left + mw + pad > vw){ left = Math.max(pad, vw - mw - pad); }
    if(left < pad){ left = pad; }

    if(top < pad){
      // If not enough room above, place below.
      top = Math.min(vh - mh - pad, r.bottom + pad);
    }

    menu.style.left = Math.round(left) + 'px';
    menu.style.top = Math.round(top) + 'px';
  }

  function closeMenu(menu){
    if(!menu) return;
    menu.setAttribute('data-open','0');
  }

  function init(){
    const plus = qs('#apai_agent_plus');
    if(!plus) return;

    const menu = buildMenu();

    plus.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      if(isOpen(menu)){
        closeMenu(menu);
        return;
      }
      openMenu(menu, plus);
    });

    // Close on outside click
    document.addEventListener('click', (e) => {
      if(!isOpen(menu)) return;
      const t = e.target;
      if(t === plus) return;
      if(menu.contains(t)) return;
      closeMenu(menu);
    });

    // Close on ESC
    document.addEventListener('keydown', (e) => {
      if(e.key === 'Escape' && isOpen(menu)){
        closeMenu(menu);
      }
    });

    // Reposition on resize/scroll when open
    const reposition = () => {
      if(!isOpen(menu)) return;
      openMenu(menu, plus);
    };
    window.addEventListener('resize', reposition);
    window.addEventListener('scroll', reposition, true);
  }

  if(document.readyState === 'loading'){
    document.addEventListener('DOMContentLoaded', init);
  }else{
    init();
  }

})();
