(function(){
  'use strict';
  document.addEventListener('click', function(event){
    var btn = event.target && event.target.closest ? event.target.closest('[data-ves-report-action]') : null;
    if(!btn){ return; }
    var action = btn.getAttribute('data-ves-report-action') || '';
    var root = btn.closest('.ves-report');
    if(action === 'copy-diagnostics'){
      var text = root ? root.textContent.replace(/\s+/g, ' ').trim() : '';
      if(navigator.clipboard && text){ navigator.clipboard.writeText(text.slice(0, 4000)); }
      btn.textContent = 'Copied';
      window.setTimeout(function(){ btn.textContent = 'Copy safe diagnostics'; }, 1600);
    }
    document.dispatchEvent(new CustomEvent('ves:report-action', { detail: { action: action, report: root } }));
  });
})();
