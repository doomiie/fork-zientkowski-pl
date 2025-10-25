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
  function toHtmlWithBreaks(s){ return escapeHTML(s).replace(/\r?\n/g,'<br>'); }
  function loadPapa(){ return new Promise(function(res){ if (typeof window!=='undefined' && window.Papa) res(window.Papa); else { var s=document.createElement('script'); s.src='https://cdn.jsdelivr.net/npm/papaparse@5.4.1/papaparse.min.js'; s.onload=function(){ res(window.Papa); }; document.head.appendChild(s);} }); }

  function ReferencesWidget(target, options){
    this.root = (typeof target==='string') ? document.querySelector(target) : target;
    if (!this.root) throw new Error('ReferencesWidget target not found');
    this.opts = merge(options, { csv:'referencje.csv', layout:'grid', shuffle:true, fields:{ imie:true, rola:true, event:true, opinia:true, data:true, source:true, avatar:true }, grid:{ pageSize:6 }, timeline:{ pageSize:6 }, carousel:{ clampLines:6, arrows:true, counter:true } });
  }

  ReferencesWidget.prototype.load = function(){
    var self=this, ds=self.root.dataset||{};
    if (ds.layout) self.opts.layout = ds.layout;
    if (ds.csv) self.opts.csv = ds.csv;
    return loadPapa().then(function(Papa){ return new Promise(function(ok,err){ Papa.parse(self.opts.csv,{download:true,header:true,skipEmptyLines:false,complete:function(r){ ok(r.data||[]); }, error:err}); }); }).then(function(rows){
      self.data = rows.filter(function(r){ return Object.values(r).some(function(v){ return (v||'').toString().trim().length>0; }); });
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
      (self.opts.fields.opinia&&opinia?'<div class="mt-3 text-sm text-gray-700">'+toHtmlWithBreaks(opinia)+'</div>':'')+
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
    function extractYear(val){ var s=String(val||''); var m=s.match(/(19|20)\d{2}/); return m?m[0]:''; }
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
      list.addEventListener('click', function(e){ var btn=e.target.closest('.rw-tl-bubble'); if(!btn) return; var idx=parseInt(btn.getAttribute('data-idx'),10); var wrap=btn.nextElementSibling; var open=wrap.classList.contains('open'); Array.prototype.slice.call(list.querySelectorAll('.rw-tl-exp.open')).forEach(function(el){ if(el!==wrap){ var h=el.scrollHeight; el.style.maxHeight=h+'px'; requestAnimationFrame(function(){ el.style.maxHeight='0px'; el.classList.remove('open'); }); } }); if(!open){ if(!wrap.dataset.filled){ var opinia=(self.data[idx]['Opinia']||''); wrap.innerHTML = '<div class="mt-2">'+toHtmlWithBreaks(opinia)+'</div>'; wrap.dataset.filled='1'; } wrap.classList.add('open'); wrap.style.maxHeight='0px'; requestAnimationFrame(function(){ var h=wrap.scrollHeight||200; wrap.style.maxHeight=h+'px'; }); } else { var h=wrap.scrollHeight; wrap.style.maxHeight=h+'px'; requestAnimationFrame(function(){ wrap.style.maxHeight='0px'; wrap.classList.remove('open'); }); } setTimeout(updateYearSegments, 300); });
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
          if(!wrap.dataset.filled){ var idx=parseInt(btn.getAttribute('data-idx'),10); var opinia=(self.data[idx]['Opinia']||''); wrap.innerHTML = '<div class="mt-2">'+toHtmlWithBreaks(opinia)+'</div>'; wrap.dataset.filled='1'; }
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
  ReferencesWidget.prototype.renderCarousel = function(){ return; };

  return ReferencesWidget;
}));
