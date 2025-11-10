// Build desktop and mobile menus from /menu.json
(function(){
  function el(tag, attrs, children){ const n=document.createElement(tag); if(attrs){ for(const k in attrs){ if(k==='class') n.className=attrs[k]; else if(k==='html') n.innerHTML=attrs[k]; else n.setAttribute(k, attrs[k]); } } (children||[]).forEach(c=>n.appendChild(c)); return n; }
  function text(s){ return document.createTextNode(s); }

  // Map various labels to lucide icon names
  function normalizeIcon(icon){
    if (!icon) return null;
    const s = String(icon).toLowerCase();
    if (s.includes('micro')) return 'mic';
    if (s.includes('chalk') || s.includes('teach') || s.includes('grad')) return 'graduation-cap';
    if (s.includes('shop') || s.includes('bag') || s.includes('cart') || s.includes('store')) return 'shopping-bag';
    if (s.includes('cal')) return 'calendar';
    if (s.includes('user') && s.includes('group')) return 'users';
    if (s.includes('user')) return 'user';
    if (s.includes('brief') || s.includes('case')) return 'briefcase';
    return s;
  }

  // Icon helpers: accept simple names like 'user','users','mic','graduation-cap','shopping-bag','calendar','briefcase'
  function circleIcon(icon){
    const name = normalizeIcon(icon); if(!name) return null;
    const wrap = el('span',{ class:'ci' });
    const i = el('i', { 'data-lucide': name, class:'w-5 h-5' });
    wrap.appendChild(i);
    return wrap;
  }


  function resolveMenuPath(){
    // Helper: normalize provided path or URL
    function norm(f){
      if (!f || typeof f !== 'string') return null;
      f = f.trim();
      if (!f) return null;
      // Allow absolute URLs
      if (/^https?:\/\//i.test(f)) return f;
      if (!/\.json$/i.test(f)) f = f + '.json';
      if (!/^\//.test(f)) f = '/' + f;
      return f;
    }

    // 1) Try query param on the script tag itself (supports <script src="...menu.js?file=...">)
    try {
      const scripts = document.getElementsByTagName('script');
      for (let i = scripts.length - 1; i >= 0; i--) {
        const s = scripts[i];
        const src = s.getAttribute('src') || '';
        if (!src) continue;
        if (src.indexOf('/assets/js/menu.js') !== -1 || /menu\.js(\?|$)/.test(src)) {
          const u = new URL(src, window.location.origin);
          const f = norm(u.searchParams.get('file'));
          if (f) return f;
        }
      }
    } catch(_){}

    // 2) Fallback: try page URL query (?file=...)
    try {
      const params = new URLSearchParams(window.location.search);
      const f2 = norm(params.get('file'));
      if (f2) return f2;
    } catch(_){}

    // 3) Default
    return '/menu.json';
  }

  async function loadMenu(){
    const path = resolveMenuPath();
    try { const res = await fetch(path, { credentials:'same-origin' }); if(!res.ok) throw new Error(path+' http '+res.status); return await res.json(); }
    catch(e){
      return { items: [
        { label:'O mnie', href:'/bio.html' },
        { label:'Usługi', children:[
          { label:'Mentoring', href:'/mentoring.html' },
          { label:'Przemówienia', href:'/products/wystapienia.html' },
          { label:'Warsztaty i szkolenia', href:'/products/szkolenia.html' },
        ]},
        { label:'Sklep', href:'/products/product.html' },
        { label:'Rezerwacje', href:'/sesja.html' },
      ]};
    }
  }

  function buildDesktop(items){
    const nav = document.getElementById('navDesktop'); if(!nav) return;
    items.forEach(item => {
      // Render a brand-styled button instead of a standard menu item
      if (String(item.type||'').toLowerCase() === 'button'){
        const isLink = !!item.href;
        const btn = isLink
          ? el('a', { href: item.href, id: item.id || '', class: 'btn-menu' }, [])
          : el('button', { id: item.id || '', class: 'btn-menu', type: 'button' }, []);
        btn.appendChild(text(item.label || ''));
        nav.appendChild(btn);
        return;
      }
      if (item.children && item.children.length){
        const wrap = el('div',{class:'relative group'});
        const parts = [];
        const ic = circleIcon(item.icon); if (ic) parts.push(ic);
        parts.push(text(item.label));
        parts.push(el('svg',{class:'w-5 h-5 me-1', viewBox:'0 0 20 20', fill:'currentColor','aria-hidden':'true'}, [ el('path',{'fill-rule':'evenodd', d:'M5.23 7.21a.75.75 0 011.06.02L10 10.94l3.71-3.71a.75.75 0 111.06 1.06l-4.24 4.24a.75.75 0 01-1.06 0L5.21 8.29a.75.75 0 01.02-1.08z','clip-rule':'evenodd'}) ]));
        const btn = el('button',{class:'nav-link relative hover:text-accent transition-colors duration-300 inline-flex items-center gap-1', 'aria-haspopup':'true','aria-expanded':'false'}, parts);
        const dd = el('div',{class:'menu-dd invisible opacity-0 group-hover:visible group-hover:opacity-100 transition absolute right-0 mt-2 w-56 rounded-lg border shadow-xl py-2'});
        item.children.forEach(ch => {
          const row = el('a',{href: ch.href || '#', class:'dd-row block px-4 py-2 text-brand-primary flex items-center gap-2'});
          const ic2 = circleIcon(ch.icon); if (ic2) row.appendChild(ic2);
          row.appendChild(text(ch.label));
          dd.appendChild(row);
        });
        wrap.appendChild(btn); wrap.appendChild(dd); nav.appendChild(wrap);
      } else {
        const a = el('a',{href: item.href || '#', class:'nav-link relative hover:text-accent transition-colors duration-300 inline-flex items-center gap-2'});
        const ic = circleIcon(item.icon); if (ic) a.appendChild(ic);
        a.appendChild(text(item.label));
        nav.appendChild(a);
      }
    });
  }

  function buildMobile(items){
    const nav = document.getElementById('navMobile'); if(!nav) return;
    const theme = (typeof window!=='undefined' && window.__menuTheme) ? window.__menuTheme : {};
    function applyRowStyles(rowEl, item){
      if (!rowEl || !item) return;
      // per-item background/text overrides
      const itBg = item['bg-color'] || (item.bg && item.bg.color) || null;
      const itText = item['text-color'] || (item.text && item.text.color) || null;
      const itBgPic = item['bg-picture'] || (item.bg && (item.bg.picture || item.bg.image)) || null;
      if (itBg) rowEl.style.backgroundColor = itBg;
      if (itBgPic) {
        rowEl.style.backgroundImage = "url('" + itBgPic + "')";
        rowEl.style.backgroundSize = 'cover';
        rowEl.style.backgroundPosition = 'center';
        rowEl.style.backgroundRepeat = 'no-repeat';
      }
      if (itText) rowEl.style.color = itText; else if (theme.textColor) rowEl.style.color = theme.textColor;
      // stretch full width of drawer, compensating nav padding (p-4 ≈ 16px)
      rowEl.style.display = 'block';
      rowEl.style.marginLeft = '-16px';
      rowEl.style.marginRight = '-16px';
      rowEl.style.marginTop = '0';
      rowEl.style.padding = '12px 16px';
      rowEl.style.borderRadius = '0';
      // separator (1–2px white line)
      const sepW = theme.separatorWidth || '1px';
      const sepC = theme.separatorColor || 'rgba(255,255,255,0.8)';
      rowEl.style.borderBottomWidth = sepW;
      rowEl.style.borderBottomStyle = 'solid';
      rowEl.style.borderBottomColor = sepC;
    }
    items.forEach(item => {
      if (String(item.type||'').toLowerCase() === 'button'){
        const isLink = !!item.href;
        const wrap = el('div', { class:'mt-3 px-3' });
        const btn = isLink
          ? el('a', { href: item.href, id: item.id || '', class: 'btn-menu block' }, [])
          : el('button', { id: item.id || '', class: 'btn-menu block', type: 'button' }, []);
        btn.appendChild(text(item.label || ''));
        // Per-item button styling
        const itBg = item['bg-color'] || (item.bg && item.bg.color) || null;
        const itText = item['text-color'] || (item.text && item.text.color) || null;
        const itBgPic = item['bg-picture'] || (item.bg && (item.bg.picture || item.bg.image)) || null;
        if (itBg) btn.style.backgroundColor = itBg;
        if (itBgPic) {
          btn.style.backgroundImage = "url('" + itBgPic + "')";
          btn.style.backgroundSize = 'cover';
          btn.style.backgroundPosition = 'center';
          btn.style.backgroundRepeat = 'no-repeat';
        }
        if (itText) btn.style.color = itText;
        // stretch full width and add separator under button
        btn.style.width = '100%';
        btn.style.borderRadius = '0';
        btn.style.marginLeft = '-16px';
        btn.style.marginRight = '-16px';
        btn.style.marginTop = '0';
        btn.style.padding = '12px 16px';
        const sepWb = theme.separatorWidth || '1px';
        const sepCb = theme.separatorColor || 'rgba(255,255,255,0.8)';
        btn.style.borderBottom = sepWb + ' solid ' + sepCb;
        wrap.appendChild(btn);
        nav.appendChild(wrap);
        return;
      }
      if (item.children && item.children.length){
        const sect = el('div',{class:'mt-2'});
        const header = el('div',{class:'px-3 pt-2 pb-1 text-xs uppercase tracking-wide text-brand-muted flex items-center gap-2 border-b border-brand-muted'});
        const icH = circleIcon(item.icon); if (icH) header.appendChild(icH);
        header.appendChild(text(item.label));
        sect.appendChild(header);
        item.children.forEach(ch => {
          const row = el('a',{href: ch.href || '#', class:'dd-row block px-3 py-2 rounded-lg text-brand-primary flex items-center gap-2'});
          applyRowStyles(row, ch);
          const ic = circleIcon(ch.icon); if (ic) row.appendChild(ic);
          row.appendChild(text(ch.label));
          sect.appendChild(row);
        });
        nav.appendChild(sect);
        nav.appendChild(el('hr',{class:'my-3'}));
      } else {
        const a = el('a',{href: item.href || '#', class:'dd-row block px-3 py-2 rounded-lg text-brand-primary flex items-center gap-2'});
        applyRowStyles(a, item);
        const ic = circleIcon(item.icon); if (ic) a.appendChild(ic);
        a.appendChild(text(item.label));
        nav.appendChild(a);
      }
    });
  }

  function applyMobileMenuTheme(conf){
    try {
      if (typeof window==='undefined') return;
      var menu = document.getElementById('mobileMenuHome'); if(!menu) return;
      var headerCloseBtn = document.getElementById('menuCloseHome');
      var theme = {};
      // Accept multiple key shapes
      var bgColor = (conf && conf.bg && conf.bg.color) || (conf && conf['bg.color']);
      var textColor = (conf && (conf['text-color'] || (conf.text && conf.text.color)));
      var bgPicture = (conf && conf.bg && (conf.bg.picture || conf.bg.image)) || (conf && (conf['bg-picture'] || conf['bg.image']));
      if (bgColor) { menu.style.backgroundColor = bgColor; }
      if (bgPicture) {
        menu.style.backgroundImage = "url('"+bgPicture+"')";
        menu.style.backgroundSize = 'cover';
        menu.style.backgroundPosition = 'center';
      }
      if (textColor) {
        menu.style.color = textColor;
        if (headerCloseBtn) headerCloseBtn.style.color = textColor;
        theme.textColor = textColor;
      }
      // default separator white (1px)
      theme.separatorColor = 'rgba(255,255,255,0.8)';
      theme.separatorWidth = '1px';
      window.__menuTheme = theme;
    } catch(_){}
  }

  function init(){ loadMenu().then(data=>{ applyMobileMenuTheme(data||{}); const items = (data && Array.isArray(data.items)) ? data.items : []; buildDesktop(items); buildMobile(items); try{ if(window.lucide && typeof window.lucide.createIcons==='function'){ window.lucide.createIcons(); } }catch(e){} }); }
  if (document.readyState==='loading') document.addEventListener('DOMContentLoaded', init); else init();
})();




