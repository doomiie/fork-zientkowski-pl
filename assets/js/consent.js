/* Minimal CMP with Google Consent Mode v2 integration */
(function(){
  var VERSION = '2025-11-05';
  var KEY = 'consent_prefs';
  var DAYS = 180; // re-prompt interval

  function now(){ return Date.now(); }
  function read(){
    try { return JSON.parse(localStorage.getItem(KEY)) || null; } catch(e){ return null; }
  }
  function write(obj){ try { localStorage.setItem(KEY, JSON.stringify(obj)); } catch(e){} }
  function daysToMs(d){ return d*24*60*60*1000; }

  function gcmDefault(){
    window.dataLayer = window.dataLayer || [];
    window.dataLayer.push({
      consent:'default',
      ad_storage:'denied', analytics_storage:'denied', ad_user_data:'denied', ad_personalization:'denied',
      functionality_storage:'granted', security_storage:'granted'
    });
  }
  function gcmUpdate(choices){
    window.dataLayer = window.dataLayer || [];
    window.dataLayer.push({
      consent:'update',
      ad_storage: choices.marketing?'granted':'denied',
      ad_user_data: choices.marketing?'granted':'denied',
      ad_personalization: choices.marketing?'granted':'denied',
      analytics_storage: choices.analytics?'granted':'denied',
      functionality_storage:'granted', security_storage:'granted'
    });
  }

  function el(tag, attrs, children){
    var n = document.createElement(tag);
    if (attrs) Object.keys(attrs).forEach(function(k){ if(k==='class') n.className=attrs[k]; else if(k==='html') n.innerHTML=attrs[k]; else n.setAttribute(k, attrs[k]); });
    (children||[]).forEach(function(c){ n.appendChild(c); });
    return n;
  }
  function loadScript(src){ return new Promise(function(res,rej){ var s=document.createElement('script'); s.src=src; s.async=true; s.onload=res; s.onerror=rej; document.head.appendChild(s); }); }

  // Safe stubs if page calls fbq/hj before consent
  if (!window.fbq) { window.fbq = function(){ /* no-op until consent granted */ }; }
  if (!window.hj) { window.hj = function(){ /* no-op until consent granted */ }; }

  function applyConsent(choices, opts){
    var pref = { version: VERSION, ts: now(), choices: choices, gpc: !!(navigator.globalPrivacyControl), region: Intl.DateTimeFormat().resolvedOptions().timeZone || '' };
    write(pref);
    gcmUpdate(choices);
    // Load gated scripts lazily
    if (choices.analytics) {
      // Hotjar loader endpoint respects admin settings
      loadScript('/admin/hotjar.js.php').catch(function(){});
    }
    if (choices.marketing) {
      loadScript('/admin/fbpixel.js.php').catch(function(){});
    }
    // Close UI if any
    var banner = document.getElementById('cc-banner'); if (banner) banner.classList.add('cc-hidden');
    var panel = document.getElementById('cc-panel'); if (panel) panel.classList.add('cc-hidden');
  }

  function showUI(){
    if (document.getElementById('cc-banner')) return; // already
    var banner = el('div',{id:'cc-banner',class:'cc-banner'},[
      el('div',{class:'cc-wrap'},[
        el('div',{class:'cc-text',html:'Używamy plików cookies do celów niezbędnych, analitycznych i marketingowych. Możesz zaakceptować wszystkie, odrzucić wszystkie lub wybrać kategorie. <a class="cc-link" href="/polityka-prywatnosci.html">Polityka prywatności</a>.'}),
        (function(){
          var box = el('div',{class:'cc-actions'});
          var btnReject = el('button',{class:'cc-btn',type:'button'},[document.createTextNode('Odrzuć wszystkie')]);
          var btnSettings = el('button',{class:'cc-btn',type:'button'},[document.createTextNode('Ustawienia')]);
          var btnAccept = el('button',{class:'cc-btn primary',type:'button'},[document.createTextNode('Akceptuj wszystkie')]);
          btnReject.addEventListener('click', function(){ applyConsent({analytics:false, marketing:false}); });
          btnAccept.addEventListener('click', function(){ applyConsent({analytics:true, marketing:true}); });
          btnSettings.addEventListener('click', function(){ openPanel(); });
          box.appendChild(btnAccept);
          box.appendChild(btnReject); 
          box.appendChild(btnSettings); 
          return box;
        })()
      ])
    ]);
    document.body.appendChild(banner);

    // Quick settings panel
    var panel = el('div',{id:'cc-panel',class:'cc-panel cc-hidden', role:'dialog','aria-modal':'true'},[
      (function(){
        var card = el('div',{class:'cc-card'},[]);
        // Close (X) button in top-right corner
        var x = el('button',{class:'cc-close',type:'button','aria-label':'Zamknij preferencje'},[document.createTextNode('×')]);
        x.onclick = function(){ closePanel(); };
        card.appendChild(x);
        // Title
        card.appendChild(el('h3',null,[document.createTextNode('Ustawienia prywatności')]));
        // Rows
        card.appendChild(el('div',{class:'cc-row'},[
          el('div',null,[el('label',null,[document.createTextNode('Niezbędne')]), el('div',{class:'cc-muted'},[document.createTextNode('Zawsze aktywne — bezpieczeństwo i podstawowe funkcje serwisu.')])])
        ]));
        (function(){
          var analytics = el('input',{type:'checkbox',id:'cc-analytics'});
          var row = el('div',{class:'cc-row'},[
            el('div',null,[el('label',{'for':'cc-analytics'},[document.createTextNode('Analityczne (Hotjar)')]), el('div',{class:'cc-muted'},[document.createTextNode('Pomaga nam zrozumieć korzystanie z serwisu.')])]),
            analytics
          ]);
          row._input = analytics; card.appendChild(row);
        })();
        (function(){
          var marketing = el('input',{type:'checkbox',id:'cc-marketing'});
          var row = el('div',{class:'cc-row'},[
            el('div',null,[el('label',{'for':'cc-marketing'},[document.createTextNode('Marketing (Facebook Pixel)')]), el('div',{class:'cc-muted'},[document.createTextNode('Personalizacja i pomiar kampanii.')])]),
            marketing
          ]);
          row._input = marketing; card.appendChild(row);
        })();
        // Actions
        card.appendChild(el('div',{class:'cc-actions'},[
          (function(){ var b=el('button',{class:'cc-btn',type:'button'},[document.createTextNode('Zamknij')]); b.onclick=function(){ closePanel(); /* do not save; keep banner visible */ }; return b; })(),
          (function(){ var b=el('button',{class:'cc-btn primary',type:'button'},[document.createTextNode('Zapisz preferencje')]); b.onclick=function(){
            var a = document.getElementById('cc-analytics').checked;
            var m = document.getElementById('cc-marketing').checked;
            applyConsent({analytics:a, marketing:m});
          }; return b; })()
        ]));
        return card;
      })()
    ]);
    document.body.appendChild(panel);
    // Close on backdrop click
    panel.addEventListener('click', function(e){ if (e.target === panel) closePanel(); });
    // Close on Escape key
    document.addEventListener('keydown', function onKey(e){ if(e.key==='Escape'){ closePanel(); } });

    // Footer link to open privacy settings
    (function(){
      var f = document.querySelector('footer');
      if (!f) return;
      var p = f.querySelector('p');
      var existing = document.getElementById('cc-footer-link');
      // If link exists but is not inside <p>, move it inline after the paragraph and remove old wrapper
      if (existing && p && existing.parentElement !== p) {
        p.appendChild(document.createTextNode(' | '));
        p.appendChild(existing);
        var wrapper = existing.parentElement;
        if (wrapper && wrapper !== p && wrapper.matches('div') && wrapper.parentElement === f) {
          try { wrapper.remove(); } catch(_) {}
        }
        return;
      }
      // Create link if missing
      if (!existing) {
        var a = el('a',{id:'cc-footer-link',href:'#',class:'cc-footer-link'},[document.createTextNode('Ustawienia prywatności')]);
        a.addEventListener('click', function(e){ e.preventDefault(); openPanel(); });
        if (p) {
          p.appendChild(document.createTextNode(' · '));
          p.appendChild(a);
        } else {
          f.appendChild(a);
        }
      }
    })();

    function openPanel(){
      var pref = read();
      var a = (pref && pref.choices && pref.choices.analytics) || false;
      var m = (pref && pref.choices && pref.choices.marketing) || false;
      var pa = document.getElementById('cc-analytics'); if (pa) pa.checked = !!a;
      var pm = document.getElementById('cc-marketing'); if (pm) pm.checked = !!m;
      panel.classList.remove('cc-hidden');
    }
    function closePanel(){ panel.classList.add('cc-hidden'); }
  }

  function boot(){
    gcmDefault();
    var pref = read();
    // Respect GPC: treat as denied unless user opted in explicitly later
    var gpc = !!(navigator.globalPrivacyControl);
    if (!pref || pref.version !== VERSION || (pref.ts && (now()-pref.ts) > daysToMs(DAYS))) {
      // default: denied
      showUI();
    } else {
      // apply stored and build UI (panel + footer link), but keep banner hidden
      applyConsent({ analytics: !!pref.choices.analytics && !gpc, marketing: !!pref.choices.marketing && !gpc });
      showUI();
      var b = document.getElementById('cc-banner');
      if (b) b.classList.add('cc-hidden');
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
