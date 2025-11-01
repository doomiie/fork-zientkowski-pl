// ReferencesWidget (UMD) — template‑strings safe
(function (root, factory) {
  if (typeof define === 'function' && define.amd) { define([], factory); }
  else if (typeof module === 'object' && module.exports) { module.exports = factory(); }
  else { root.ReferencesWidget = factory(); }
}(typeof self !== 'undefined' ? self : this, function () {
  function merge(a, b) {
    a = a || {}; b = b || {};
    for (var k in b) if (Object.prototype.hasOwnProperty.call(b, k)) {
      if (typeof b[k] === 'object' && b[k] !== null && !Array.isArray(b[k])) a[k] = merge(a[k] || {}, b[k]);
      else if (!(k in a)) a[k] = b[k];
    }
    return a;
  }
  function escapeHTML(s){
    return String(s||'').replace(/[&<>"']/g, function(m){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]); });
  }
  function toHtmlWithBreaks(s){ return escapeHTML(s).replace(/\\r?\\n/g,'<br>'); }
  function boldTokensKeepingTags(html, keywords){
    if (!html || !keywords || !keywords.length) return html;
    var lowerKw = keywords.map(function(k){ return (k||'').toString().toLowerCase(); }).filter(Boolean);
    if (!lowerKw.length) return html;
    var parts = html.split(/(<[^>]+>)/g);
    var wordRe = /[A-Za-zÀ-ÖØ-öø-ÿĄąĆćĘęŁłŃńÓóŚśŹźŻż]+/g;
    for (var i=0;i<parts.length;i++){
      var p = parts[i];
      if (!p || p[0] === '<') continue;
      parts[i] = p.replace(wordRe, function(tok){
        var t = tok.toLowerCase();
        for (var j=0;j<lowerKw.length;j++){
          if (t.indexOf(lowerKw[j]) !== -1) return '<b>' + tok + '</b>';
        }
        return tok;
      });
    }
    return parts.join('');
  }
  function loadPapa(){ return new Promise(function(res){ if (typeof window!=='undefined' && window.Papa) res(window.Papa); else { var s=document.createElement('script'); s.src='https://cdn.jsdelivr.net/npm/papaparse@5.4.1/papaparse.min.js'; s.onload=function(){ res(window.Papa); }; document.head.appendChild(s);} }); }
  function ensureRWStyles(){
    if (typeof document==='undefined') return;
    if (document.getElementById('rw-styles')) return;
    var st = document.createElement('style');
    st.id = 'rw-styles';
    st.textContent = ".ref-summary.no-clamp{display:block!important;-webkit-line-clamp:initial!important;-webkit-box-orient:initial!important;overflow:visible!important}";
    document.head.appendChild(st);
  }

  function ReferencesWidget(target, options){
    this.root = (typeof target==='string') ? document.querySelector(target) : target;
    if (!this.root) throw new Error('ReferencesWidget target not found');
    this.opts = merge(options, { csv:'./assets/txt/referencje.csv', layout:'grid', shuffle:true, fields:{ imie:true, rola:true, event:true, opinia:true, data:true, source:true, avatar:true }, grid:{ pageSize:6 }, timeline:{ pageSize:6 }, carousel:{ clampLines:6, arrows:true, counter:true }, keywords:'' });
    var kw = (this.opts.keywords||'').toString().split(/[;,\s]+/).map(function(x){return x.trim();}).filter(Boolean);
    this._kw = kw;
  }

  ReferencesWidget.prototype.load = function(){
    var self=this, ds=self.root.dataset||{};
    if (ds.layout) self.opts.layout = ds.layout;
    if (ds.csv) self.opts.csv = ds.csv;
    return loadPapa().then(function(Papa){ return new Promise(function(ok,err){ Papa.parse(self.opts.csv,{download:true,header:true,skipEmptyLines:false,complete:function(r){ ok(r.data||[]); }, error:err}); }); }).then(function(rows){
      
      self.data = rows.filter(function(r){ return Object.values(r).some(function(v){ return (v||'').toString().trim().length>0; }); });
      
      if (self._kw && self._kw.length){
        var kws = self._kw.map(function(k){return k.toLowerCase();});
        
        var before = self.data.length;
        self.data = self.data.filter(function(r){
          for (var k in r){ if (!Object.prototype.hasOwnProperty.call(r,k)) continue; var val=(r[k]||'').toString().toLowerCase(); for (var i=0;i<kws.length;i++){ if (kws[i] && val.indexOf(kws[i])!==-1){ return true; } }
          }
          return false;
        });
        
      } else {
        
      }
      if (self.opts.shuffle){ for (var i=self.data.length-1;i>0;i--){ var j=Math.floor(Math.random()*(i+1)), t=self.data[i]; self.data[i]=self.data[j]; self.data[j]=t; } }
      self.render();
    }).catch(function(){ self.root.innerHTML = '<div class="text-gray-600">Nie udało się wczytać referencji.</div>'; });
  };

  ReferencesWidget.prototype.render = function(){
    var l=this.opts.layout;
    if (l==='grid') return this.renderGrid();
    if (l==='timeline') return this.renderTimeline();
    if (l==='carousel') return this.renderCarousel();
    this.root.innerHTML = '<div class="text-gray-600">Nieznany layout</div>';
  };

  // GRID
  ReferencesWidget.prototype.renderGrid = function(){
    
    var self=this, ps=self.opts.grid.pageSize||6, shown=0;
    this.root.innerHTML = '<div id="rw-grid" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-6"></div>'+
      '<div class="mt-6 text-center"><button id="rw-more" class="px-6 py-3 rounded-xl border border-gray-300 bg-white hover:bg-gray-50 text-dark font-medium shadow-sm">Pokaż '+ps+' więcej</button></div>';
    var grid=this.root.querySelector('#rw-grid'), more=this.root.querySelector('#rw-more');
    function card(it, index){ var name=(it['Imię, nazwisko']||'').trim(), role=(it['Rola']||'').trim(), opinia=(it['Opinia']||'').trim(), data=(it['Data']||'').trim(), avatar=(it['Zdjęcie profilowe']||'').trim(); var av=(self.opts.fields.avatar&&avatar)?('<img class="w-10 h-10 rounded-full object-cover" src="'+escapeHTML(avatar)+'" alt="'+escapeHTML(name||'')+'">'):''; return '<article class="bg-white border border-gray-200 rounded-xl shadow-sm p-5 h-full">'+
      '<div class="flex items-start gap-3">'+av+
        '<div class="min-w-0">'+
          (self.opts.fields.imie&&name?'<div class="font-semibold text-dark truncate">'+escapeHTML(name)+'</div>':'')+
          (self.opts.fields.rola&&role?'<div class="text-xs text-gray-500 truncate">'+escapeHTML(role)+'</div>':'')+
        '</div>'+
        (self.opts.fields.data&&data?'<div class="ml-auto text-xs text-gray-500">'+escapeHTML(data)+'</div>':'')+
      '</div>'+
      (self.opts.fields.opinia&&opinia?'<div class="mt-3 text-sm text-gray-700">'+boldTokensKeepingTags(toHtmlWithBreaks(opinia), self._kw)+'</div>':'')+
      '<div class="mt-3 flex items-center justify-between"><span class="text-xs text-transparent">.</span><span class="text-xs text-gray-500">'+(index+1)+'/'+self.data.length+'</span></div>'+
    '</article>'; }
    function renderMore(){ var next=self.data.slice(shown, shown+ps); grid.insertAdjacentHTML('beforeend', next.map(function(it,i){ return card(it, shown+i); }).join('')); shown+=next.length; if (shown>=self.data.length){ more.disabled=true; more.textContent='Nic więcej do pokazania'; } }
    more.addEventListener('click', renderMore); renderMore();
  };

  // TIMELINE
  ReferencesWidget.prototype.renderTimeline = function(){
    var self=this, ps=self.opts.timeline.pageSize||6, shown=0;
    this.root.innerHTML = '<div class="timeline" style="position:relative; z-index:0;"><div id="rw-tl-list" class="space-y-8 md:space-y-12"></div></div>'+
      '<div class="mt-6 text-center space-x-2">'+
        '<button id="rw-tl-more" class="inline-block px-6 py-3 rounded-xl border border-gray-300 bg-white hover:bg-gray-50 text-dark font-medium shadow-sm">Pokaż '+ps+' kolejnych</button>'+
        '<button id="rw-tl-all" class="inline-block px-6 py-3 rounded-xl border border-gray-300 bg-white hover:bg-gray-50 text-dark font-medium shadow-sm">Pokaż wszystkie</button>'+
      '</div>';
    var list=this.root.querySelector('#rw-tl-list'), more=this.root.querySelector('#rw-tl-more');
    var showAllBtn=this.root.querySelector('#rw-tl-all');
    function extractYear(val){ var s=String(val||''); var m=s.match(/(19|20)\\d{2}/); return m?m[0]:''; }
    function hashString(s){ var h=0; for(var i=0;i<s.length;i++){ h=((h<<5)-h)+s.charCodeAt(i); h|=0; } return Math.abs(h); }
    var palette=['#f43f5e','#f59e0b','#059669','#0ea5e9','#8b5cf6','#d946ef','#65a30d','#06b6d4'];
    function getYearColor(y){ if(!y) return '#c7d2fe'; return palette[ hashString(String(y)) % palette.length ]; }
    // sort ascending by year
    self.data.sort(function(a,b){ return (parseInt(extractYear(a['Data']),10)||9999) - (parseInt(extractYear(b['Data']),10)||9999); });
    var lastYear=null;
    var dateTotal = self.data.reduce(function(acc,it){ return acc + (extractYear(it['Data'])?1:0); }, 0);
    function yearBadge(y){ var c=getYearColor(y); return '<div class="relative" data-year-marker="'+(y||'')+'">'+
      '<div class="absolute left-1/2 -translate-x-1/2 -translate-y-1/2 -top-4"><span class="inline-block px-3 py-1 rounded-full text-white text-xs font-semibold shadow" style="background-color:'+c+'">'+(y||'brak daty')+'</span></div>'+
      '<div class="h-6"></div></div>'; }
    function bubble(it, idx, dateIndex){
      var name=(it['Imię, nazwisko']||'').trim(), role=(it['Rola']||'').trim(), data=(it['Data']||'').trim();
      var avatar=(it['Zdjęcie profilowe']||'').trim();
      var avatarHtml=(self.opts.fields.avatar && avatar)?('<img class="w-8 h-8 rounded-full object-cover" src="'+escapeHTML(avatar)+'" alt="'+escapeHTML(name||'')+'">'):'';
      var year=extractYear(data);
      var nameHtml=(self.opts.fields.imie&&name?'<div class="font-semibold text-dark truncate">'+escapeHTML(name)+'</div>':'');
      var roleHtml=(self.opts.fields.rola&&role?'<div class="text-xs text-gray-500 truncate">'+escapeHTML(role)+'</div>':'');
      function bubbleCard(idx){
        return (
          '<article class="rw-tl-card bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden w-full" style="position:relative; z-index:2;">'+
            '<button class="rw-tl-bubble w-full text-left px-4 py-3 flex items-center gap-3" data-idx="'+idx+'">'+
              avatarHtml+
              '<div class="min-w-0">'+nameHtml+roleHtml+'</div>'+
              (self.opts.fields.data&&data?'<div class="ml-auto text-xs text-gray-500">'+escapeHTML(data)+'</div>':'')+
            '</button>'+
            '<div class="rw-tl-exp disclosure-expander text-gray-700 text-sm px-4 pb-3"></div>'+
            (year?'<div class="px-4 pb-3 flex items-center justify-between"><span class="text-xs text-transparent">.</span><span class="text-xs text-gray-500">'+dateIndex+'/'+dateTotal+'</span></div>':'')+
          '</article>'
        );
      }
      var side = (idx % 2 === 0) ? 'left' : 'right';
      return (year && year!==lastYear? (lastYear=year, yearBadge(year)) : '')+
      '<div class="relative md:grid md:grid-cols-2 items-stretch">'+
        '<div class="hidden md:flex justify-end md:pr-6 flex-col">'+(side==='left'? bubbleCard(idx): '')+'</div>'+
        '<div class="hidden md:flex justify-start md:pl-6 flex-col">'+(side==='right'? bubbleCard(idx): '')+'</div>'+
        '<div class="md:hidden flex flex-col">'+bubbleCard(idx)+'</div>'+
        '<span class="timeline-dot" style="position:absolute; left:50%; transform:translate(-50%,-50%); top:50%; width:12px; height:12px; border-radius:9999px; background:#d1d5db; border:2px solid #fff; box-shadow:0 0 0 1px #e5e7eb; z-index:0;"></span>'+
      '</div>';
    }
    function updateYearSegments(){ var container=self.root.querySelector('.timeline'); if(!container) return; Array.prototype.slice.call(container.querySelectorAll('.year-segment')).forEach(function(el){ el.remove(); }); var cr=container.getBoundingClientRect(); var listRect=list.getBoundingClientRect(); var bottom=listRect.bottom - cr.top; var markers=Array.prototype.slice.call(list.querySelectorAll('[data-year-marker]')); markers.forEach(function(m,i){ var year=m.getAttribute('data-year-marker')||''; var top=m.getBoundingClientRect().top - cr.top; var next=(markers[i+1]? (markers[i+1].getBoundingClientRect().top - cr.top) : bottom); var seg=document.createElement('div'); seg.className='year-segment'; seg.style.position='absolute'; seg.style.left='50%'; seg.style.width='2px'; seg.style.transform='translateX(-1px)'; seg.style.zIndex='0'; seg.style.top=Math.max(0,top)+'px'; seg.style.height=Math.max(0,next-top)+'px'; seg.style.backgroundColor=getYearColor(year); container.appendChild(seg); }); }
    function bind(){
      // click toggle
      list.addEventListener('click', function(e){ var btn=e.target.closest('.rw-tl-bubble'); if(!btn) return; var idx=parseInt(btn.getAttribute('data-idx'),10); var wrap=btn.nextElementSibling; var open=wrap.classList.contains('open'); Array.prototype.slice.call(list.querySelectorAll('.rw-tl-exp.open')).forEach(function(el){ if(el!==wrap){ var h=el.scrollHeight; el.style.maxHeight=h+'px'; requestAnimationFrame(function(){ el.style.maxHeight='0px'; el.classList.remove('open'); }); } }); if(!open){ if(!wrap.dataset.filled){ var opinia=(self.data[idx]['Opinia']||''); wrap.innerHTML = '<div class="mt-2">'+boldTokensKeepingTags(toHtmlWithBreaks(opinia), self._kw)+'</div>'; wrap.dataset.filled='1'; } wrap.classList.add('open'); wrap.style.maxHeight='0px'; requestAnimationFrame(function(){ var h=wrap.scrollHeight||200; wrap.style.maxHeight=h+'px'; }); } else { var h=wrap.scrollHeight; wrap.style.maxHeight=h+'px'; requestAnimationFrame(function(){ wrap.style.maxHeight='0px'; wrap.classList.remove('open'); }); } setTimeout(updateYearSegments, 300); });
      // hover expand for pointer devices
      var mql = window.matchMedia('(hover: hover) and (pointer: fine)'); var hoverEnabled = mql.matches; if (mql.addEventListener) mql.addEventListener('change', function(){ hoverEnabled=mql.matches; });
      var closeTimer;
      list.addEventListener('mouseover', function(e){
        if(!hoverEnabled) return;
        var btn=e.target.closest('.rw-tl-bubble'); if(!btn || !list.contains(btn)) return;
        if (closeTimer){ clearTimeout(closeTimer); closeTimer=null; }
        var wrap=btn.nextElementSibling;
        if (!wrap.classList.contains('open')){
          // close other open items (accordion behavior)
          Array.prototype.slice.call(list.querySelectorAll('.rw-tl-exp.open')).forEach(function(el){
            if (el!==wrap){ var h=el.scrollHeight; el.style.maxHeight=h+'px'; requestAnimationFrame(function(){ el.style.maxHeight='0px'; el.classList.remove('open'); }); }
          });
          if(!wrap.dataset.filled){ var idx=parseInt(btn.getAttribute('data-idx'),10); var opinia=(self.data[idx]['Opinia']||''); wrap.innerHTML = '<div class="mt-2">'+boldTokensKeepingTags(toHtmlWithBreaks(opinia), self._kw)+'</div>'; wrap.dataset.filled='1'; }
          wrap.classList.add('open');
          wrap.style.maxHeight='0px';
          requestAnimationFrame(function(){ var h=wrap.scrollHeight||200; wrap.style.maxHeight=h+'px'; });
          setTimeout(updateYearSegments, 300);
        }
      });
      list.addEventListener('mouseout', function(e){ if(!hoverEnabled) return; var row=e.target.closest('div'); if(!row) return; var related=e.relatedTarget; if (related && row.contains(related)) return; var btn=e.target.closest('.rw-tl-bubble'); if(!btn) return; var wrap=btn.nextElementSibling; if (!wrap.classList.contains('open')) return; closeTimer=setTimeout(function(){ var h=wrap.scrollHeight; wrap.style.maxHeight=h+'px'; requestAnimationFrame(function(){ wrap.style.maxHeight='0px'; wrap.classList.remove('open'); }); setTimeout(updateYearSegments, 300); }, 150); });
      window.addEventListener('resize', function(){ setTimeout(updateYearSegments, 100); }, {passive:true});
    }
    function renderMore(){ var next=self.data.slice(shown, shown+ps); var renderedDate=0; // count within this pass starting from already rendered
      // compute how many with date already rendered
      var existingCards = list.querySelectorAll('.rw-tl-card').length; // approximate
      var beforeSlice = self.data.slice(0, shown);
      renderedDate = beforeSlice.reduce(function(acc,it){ return acc + (extractYear(it['Data'])?1:0); }, 0);
      var html='';
      next.forEach(function(it,i){ var year=extractYear(it['Data']); var idxDate = renderedDate + (year?1:0); html += bubble(it, shown+i, year?idxDate:null); if (year) renderedDate++; });
      list.insertAdjacentHTML('beforeend', html);
      if (shown===0) bind(); shown+=next.length; updateYearSegments(); if (shown>=self.data.length){ more.disabled=true; more.textContent='Nic więcej do pokazania'; }
    }
    more.addEventListener('click', renderMore);
    if (showAllBtn){ showAllBtn.addEventListener('click', function(){ while (shown < self.data.length) renderMore(); showAllBtn.disabled=true; }); }
    renderMore();
  };

  // CAROUSEL — no-op (używasz własnej implementacji w humor.html)
  // CAROUSEL - horizontal scroller using behavior from humor.html
  ReferencesWidget.prototype.renderCarousel = function(){
    ensureRWStyles();
    var self=this;
    var root = this.root;
    if (!root) return;

    var scroller = root;
    if (!(scroller.classList && (scroller.classList.contains('overflow-x-auto') || scroller.classList.contains('overflow-x-scroll')))){
      scroller = root.querySelector('#refScroller') || (function(){
        var el = document.createElement('div');
        el.id = 'refScroller';
        el.className = 'hide-scrollbar overflow-x-auto overscroll-x-contain px-10';
        el.style.scrollPaddingLeft = '2.5rem';
        el.style.scrollPaddingRight = '2.5rem';
        root.appendChild(el);
        return el;
      })();
    }

    var track = scroller.querySelector('#refTrack') || (function(){
      var el = document.createElement('div');
      el.id = 'refTrack';
      el.className = 'flex gap-4 snap-x snap-mandatory py-2 items-stretch';
      scroller.appendChild(el);
      return el;
    })();

    var prevBtn = document.getElementById('refPrev');
    var nextBtn = document.getElementById('refNext');
    var counterEl = document.getElementById('refCounter');
    var wantArrows = !!(self.opts && self.opts.carousel && self.opts.carousel.arrows);
    var wantCounter = !!(self.opts && self.opts.carousel && self.opts.carousel.counter);
    if ((wantArrows || wantCounter) && (!prevBtn && !nextBtn && !counterEl)){
      var ctr = root.querySelector('.rw-controls');
      if (!ctr){
        ctr = document.createElement('div');
        ctr.className = 'rw-controls hidden md:flex items-center gap-3 mb-3';
        root.insertBefore(ctr, scroller);
      }
      if (wantCounter){
        counterEl = document.createElement('span');
        counterEl.className = 'rw-counter text-sm text-gray-600';
        counterEl.id = 'refCounter'; counterEl.textContent = '0/0';
        ctr.appendChild(counterEl);
      }
      if (wantArrows){
        prevBtn = document.createElement('button');
        prevBtn.setAttribute('aria-label','Poprzednie');
        prevBtn.className = 'rw-prev p-2 rounded-lg border border-gray-300 bg-white text-dark hover:bg-gray-50 shadow-sm disabled:opacity-40 disabled:cursor-not-allowed';
        prevBtn.id = 'refPrev';
        prevBtn.innerHTML = '<svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>';
        ctr.appendChild(prevBtn);
        nextBtn = document.createElement('button');
        nextBtn.setAttribute('aria-label','Następne');
        nextBtn.className = 'rw-next p-2 rounded-lg border border-gray-300 bg-white text-dark hover:bg-gray-50 shadow-sm disabled:opacity-40 disabled:cursor-not-allowed';
        nextBtn.id = 'refNext';
        nextBtn.innerHTML = '<svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>';
        ctr.appendChild(nextBtn);
      }
    }

    var clampLines = (self.opts && self.opts.carousel && self.opts.carousel.clampLines) ? self.opts.carousel.clampLines : 6;

    function pick(obj, substrs){
      var key = Object.keys(obj || {}).find(function(k){
        var s = (k||'').toLowerCase();
        return substrs.every(function(sub){ return s.indexOf(sub) >= 0; });
      });
      return key ? obj[key] : '';
    }

    function cardTemplate(item, idx){
      var name = (item['Imie, nazwisko'] || pick(item, ['imi','nazw']) || '').trim();
      var role = (item['Rola'] || '').trim();
      var opinia = (item['Opinia'] || '').trim();
      var data = (item['Data'] || '').trim();
      var avatar = (pick(item, ['zdj','profil']) || '').trim();
      var hasAvatar = !!avatar;
      var avatarHtml = hasAvatar ? '<img src="'+escapeHTML(avatar)+'" alt="'+escapeHTML(name||'Zdjecie profilowe')+'" class="w-10 h-10 rounded-full object-cover" loading="lazy">' : '';
      var clampClass = 'line-clamp-'+String(clampLines);
      return (
        '<article class="ref-card snap-start shrink-0 w-[280px] sm:w-[320px] md:w-[360px] bg-white border border-gray-200 rounded-xl shadow-sm p-5" data-idx="'+idx+'">'+
          '<div class="flex items-start gap-3">'+
            avatarHtml+
            '<div class="min-w-0">'+
              (name?'<div class="font-semibold text-dark truncate">'+escapeHTML(name)+'</div>':'')+
              (role?'<div class="text-xs text-gray-500 truncate">'+escapeHTML(role)+'</div>':'')+
            '</div>'+
            (data?'<div class="ml-auto text-xs text-gray-500">'+escapeHTML(data)+'</div>':'')+
          '</div>'+
          (opinia?'<div class="ref-summary mt-3 text-sm text-gray-700 '+clampClass+'">'+boldTokensKeepingTags(toHtmlWithBreaks(opinia), self._kw)+'</div>':'')+
          '<div class="ref-caret-wrap mt-2 flex items-center justify-center">'+
            '<span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-indigo-50 text-indigo-700">'+
              '<svg class="ref-caret h-4 w-4 transition-transform" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 10.94l3.71-3.71a.75.75 0 111.06 1.06l-4.24 4.24a.75.75 0 01-1.06 0L5.21 8.29a.75.75 0 01.02-1.08z" clip-rule="evenodd"/></svg>'+
            '</span>'+
          '</div>'+
          '<div class="ref-expander disclosure-expander text-gray-700 text-sm md:text-base"></div>'+
        '</article>'
      );
    }
    /*

    */
    // Virtualize: render just enough to fill viewport, append on demand
    var sampleHtml = self.data && self.data.length ? cardTemplate(self.data[0], 0) : '';
    track.innerHTML = sampleHtml;
    var sampleCard = track.querySelector('.ref-card');
     var cardW = sampleCard ? sampleCard.getBoundingClientRect().width : 320; var gap = (function(){ try { var s = getComputedStyle(track); var g = parseFloat(s.columnGap || s.gap || '16'); return isNaN(g)?16:g; } catch(e){ return 16; }})(); track.innerHTML = '';
    var pageSize = Math.max(2, Math.ceil((scroller.clientWidth + gap) / Math.max(1, Math.floor(cardW + gap))) + 1);
    var rendered = 0;
    function applyClamp(el){
      var s = el.querySelector('.ref-summary');
      if (!s) return;
      s.style.display = '-webkit-box';
      s.style.webkitLineClamp = String(clampLines);
      s.style.webkitBoxOrient = 'vertical';
      s.style.overflow = 'hidden';
      var caret = el.querySelector('.ref-caret');
      var truncated = s.scrollHeight > s.clientHeight + 1;
      if (!truncated){
        var clone = s.cloneNode(true);
        clone.classList.add('no-clamp');
        clone.style.position='fixed'; clone.style.left='-9999px'; clone.style.top='0';
        clone.style.width = s.clientWidth+'px';
        document.body.appendChild(clone);
        truncated = clone.scrollHeight > s.clientHeight + 1;
        document.body.removeChild(clone);
      }
      s.dataset.truncated = truncated ? '1' : '0';
      if (!truncated && caret && caret.parentElement){ caret.parentElement.style.visibility='hidden'; }
    }
    function appendMore(count){
      var end = Math.min(self.data.length, rendered + count);
      var html = '';
      for (var i=rendered; i<end; i++){ html += cardTemplate(self.data[i], i); }
      track.insertAdjacentHTML('beforeend', html);
      var cards = track.querySelectorAll('.ref-card');
      for (var j=rendered; j<end; j++) applyClamp(cards[j]);
      rendered = end;
    }
    appendMore(pageSize); if ((scroller.scrollWidth - scroller.clientWidth) <= 0 && rendered < (self.data ? self.data.length : 0)) { appendMore(pageSize); }

    function ensureMore(force){
      if (!self.data || rendered >= self.data.length) return;
      if (force) { appendMore(pageSize); if ((scroller.scrollWidth - scroller.clientWidth) <= 0 && rendered < (self.data ? self.data.length : 0)) { appendMore(pageSize); } return; }
      var nearEnd = (scroller.scrollLeft + scroller.clientWidth*1.2) >= (scroller.scrollWidth - scroller.clientWidth);
      if (nearEnd) appendMore(pageSize); if ((scroller.scrollWidth - scroller.clientWidth) <= 0 && rendered < (self.data ? self.data.length : 0)) { appendMore(pageSize); }
    }

    track.addEventListener('click', function(e){
      var card = e.target.closest('.ref-card');
      if (!card) return;
      var exp = card.querySelector('.ref-expander');
      var summary = card.querySelector('.ref-summary');
      var caret = card.querySelector('.ref-caret');
      var caretWrap = card.querySelector('.ref-caret-wrap');
      if (!summary || summary.dataset.truncated !== '1') return;
      var isOpen = exp.classList.contains('open');
      track.querySelectorAll('.ref-expander.open').forEach(function(el){
        if (el!==exp){
          el.style.transition = 'max-height 260ms ease';
          var h = el.scrollHeight; el.style.maxHeight = h+'px';
          requestAnimationFrame(function(){ el.style.maxHeight='0px'; el.classList.remove('open'); });
          var c = el.closest('.ref-card') && el.closest('.ref-card').querySelector('.ref-caret');
          if (c) c.style.transform = 'rotate(0deg)';
          setTimeout(function(){
            var sEl = el.querySelector('.ref-summary');
            var wEl = el.querySelector('.ref-caret-wrap');
            if (sEl){ el.parentNode && el.parentNode.insertBefore(sEl, el); sEl.classList.remove('no-clamp'); }
            if (wEl){ el.parentNode && el.parentNode.insertBefore(wEl, el); }
          }, 260);
        }
      });
      if (!isOpen){
        if (summary.parentElement !== exp){ exp.appendChild(summary); summary.classList.add('no-clamp'); }
        if (caretWrap && caretWrap.parentElement !== exp){ exp.appendChild(caretWrap); }
        exp.classList.add('open');
        exp.style.transition = 'max-height 260ms ease';
        exp.style.maxHeight='0px';
        requestAnimationFrame(function(){ var h2=exp.scrollHeight||200; exp.style.maxHeight=h2+'px'; });
        if (caret) caret.style.transform = 'rotate(180deg)';
      } else {
        exp.style.transition = 'max-height 260ms ease';
        var h3=exp.scrollHeight; exp.style.maxHeight=h3+'px';
        requestAnimationFrame(function(){ exp.style.maxHeight='0px'; exp.classList.remove('open'); });
        setTimeout(function(){
          if (summary && exp.contains(summary)){
            exp.parentNode && exp.parentNode.insertBefore(summary, exp);
            summary.classList.remove('no-clamp');
          }
          if (caretWrap && exp.contains(caretWrap)){
            exp.parentNode && exp.parentNode.insertBefore(caretWrap, exp);
          }
        }, 260);
        if (caret) caret.style.transform = 'rotate(0deg)';
      }
      updateNav();
    });

    function updateNav(){
      var maxScroll = scroller.scrollWidth - scroller.clientWidth - 2;
      var x = scroller.scrollLeft;
      if (prevBtn) prevBtn.disabled = (x<=0);
      if (nextBtn) nextBtn.disabled = (x>=maxScroll && rendered >= (self.data ? self.data.length : 0));
      if (counterEl){
        var cards = track.querySelectorAll('.ref-card');
        var current = 1; var minDist = Infinity;
        var cont = scroller.getBoundingClientRect();
        cards.forEach(function(card, i){
          var rect = card.getBoundingClientRect();
          var dist = Math.abs(rect.left - cont.left);
          if (dist < minDist){ minDist = dist; current = i+1; }
        });
        counterEl.textContent = current+'/' + (self.data ? self.data.length : cards.length);
      }
    }

    if (prevBtn){ prevBtn.addEventListener('click', function(){ scroller.scrollBy({left: -Math.max(280, Math.floor(scroller.clientWidth*0.9)), behavior:'smooth'}); }); }
    if (nextBtn){ nextBtn.addEventListener('click', function(){ ensureMore(true); scroller.scrollBy({left: Math.max(280, Math.floor(scroller.clientWidth*0.9)), behavior:'smooth'}); setTimeout(function(){ ensureMore(); }, 80); }); }
    scroller.addEventListener('scroll', function(){ updateNav(); ensureMore(); }, {passive:true});
    window.addEventListener('resize', function(){ updateNav(); ensureMore(); }, {passive:true});
    updateNav();
  };

  return ReferencesWidget;
}));










