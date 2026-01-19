(function(){
  'use strict';

  function qs(sel, root){ return (root||document).querySelector(sel); }

  function setCollapsed(shell, collapsed){
    if (!shell) return;
    shell.classList.toggle('apai-shell--collapsed', !!collapsed);
    try {
      window.localStorage.setItem('apai_shell_collapsed', collapsed ? '1' : '0');
    } catch (e) {}
  }

  function readCollapsed(){
    try {
      return window.localStorage.getItem('apai_shell_collapsed') === '1';
    } catch (e) {
      return false;
    }
  }

  function updateEmptyState(shell){
    var msgs = qs('#apai_agent_messages');
    var empty = !msgs || !msgs.children || msgs.children.length === 0;
    shell.classList.toggle('apai-shell--empty', !!empty);
  }

  document.addEventListener('DOMContentLoaded', function(){
    var shell = qs('#apai_shell');
    if (!shell) return;

    document.body.classList.add('apai-shell-active');

    // Initial collapsed state
    setCollapsed(shell, readCollapsed());

    // Toggle button
    var btn = qs('#apai_side_toggle');
    if (btn) {
      btn.addEventListener('click', function(){
        var nowCollapsed = !shell.classList.contains('apai-shell--collapsed');
        setCollapsed(shell, nowCollapsed);
      });
    }

    // Empty state observer (for the Gemini-like centered composer)
    updateEmptyState(shell);
    var msgs = qs('#apai_agent_messages');
    if (msgs && window.MutationObserver) {
      var obs = new MutationObserver(function(){ updateEmptyState(shell); });
      obs.observe(msgs, { childList: true, subtree: false });
    }
  });
})();
