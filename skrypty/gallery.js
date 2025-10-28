// Simple Gallery class: loads JSON and renders masonry grid
(function(root, factory){
  if (typeof define==='function' && define.amd){ define([], factory); }
  else if (typeof module==='object' && module.exports){ module.exports = factory(); }
  else { root.Gallery = factory(); }
}(typeof self!=='undefined' ? self : this, function(){
  function ensureStyles(){
    if (typeof document==='undefined') return;
    if (document.getElementById('gallery-masonry-styles')) return;
    console.log("Adding gallery masonry styles");
    var st = document.createElement('style');
    st.id = 'gallery-masonry-styles';
    st.textContent = 
      ".masonry{column-gap:1.25rem}"+
      ".masonry .gallery-item{position:relative;background:transparent!important;aspect-ratio:auto!important;box-shadow:none}"+
      ".masonry .masonry-item{break-inside:avoid;margin-bottom:1.25rem;display:block}"+
      ".masonry .masonry-item img,.masonry .masonry-item picture{width:100%;height:auto;display:block;border-radius:16px}"+
      "@media (min-width:640px){.masonry{columns:2}}"+
      "@media (min-width:1024px){.masonry{columns:3}}"+
      "@media (min-width:1280px){.masonry{columns:4}}.masonry .gallery-overlay{position:absolute;inset:0;background:#ffffff!important;opacity:0;transition:opacity .25s ease;display:flex;align-items:center;justify-content:center;padding:1.5rem;z-index:3}.masonry .gallery-item:hover .gallery-overlay{opacity:0.7}.masonry .gallery-content{text-align:center;color:#111}"+
      "@media (min-width:1536px){.masonry{columns:5}}";
    document.head.appendChild(st);
  }
  function safeURL(u){ try { return u ? encodeURI(u) : u; } catch(e){ return u; } }

  function Gallery(target, options){
    this.root = (typeof target==='string') ? document.querySelector(target) : target;
    if (!this.root) throw new Error('Gallery target not found');
    this.opts = Object.assign({ json: 'assets/gallery.json' }, options||{});
    try { console.log('[Gallery] init', { target: target, rootFound: !!this.root, json: this.opts.json }); } catch(e){}
  }

  Gallery.prototype.load = function(){
    var self=this;
    ensureStyles();
    try { console.log('[Gallery] load start', { json: self.opts.json }); } catch(e){}
    return fetch(self.opts.json, {cache:'no-cache'})
      .then(function(r){ try { console.log('[Gallery] fetch response', { all:r, status: r.status, ok: r.ok }); } catch(e){}; if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); })
      .then(function(items){
        var fileItems = Array.isArray(items) ? items : [];
        var domItems = self.fallbackFromDom();
        // Prefer richer/larger source: if DOM has more entries, use it; otherwise use JSON
        self.data = (domItems && domItems.length > fileItems.length) ? domItems : fileItems;
        try { console.log('[Gallery] parsed items', { jsonCount: fileItems.length, domCount: domItems.length, used: self.data.length }); } catch(e){}
        self.render();
      })
      .catch(function(err){
        try { console.warn('[Gallery] JSON load failed, falling back to DOM', err); } catch(e){}
        self.data = self.fallbackFromDom();
        try { console.log('[Gallery] fallback items', { count: self.data.length }); } catch(e){}
        self.render();
      });
  };

  Gallery.prototype.fallbackFromDom = function(){
    var out = [];
    try {
      var grid = document.querySelector('.gallery-grid');
      if (!grid) return out;
      var items = grid.querySelectorAll('.gallery-item');
      items.forEach(function(el){
        var img = el.querySelector('img');
        if (!img) return;
        var src = img.getAttribute('src') || '';
        var alt = img.getAttribute('alt') || '';
        var jpg = /\.webp(\?.*)?$/i.test(src) ? src.replace(/\.webp(\?.*)?$/i, '.jpg') : src;
        var webp = /\.webp(\?.*)?$/i.test(src) ? src : '';
        var h3 = el.querySelector('.gallery-content h3');
        var p = el.querySelector('.gallery-content p');
        var title = h3 ? (h3.textContent || '').trim() : '';
        var desc = p ? (p.textContent || '').trim() : '';
        var a = el.querySelector('.gallery-zoom a');
        var download = a ? (a.getAttribute('href') || '') : jpg;
        var downloadLabel = a ? ((a.textContent || '').trim()) : '';
        out.push({ jpg: jpg, webp: webp, title: title, desc: desc, alt: alt, download: download, downloadLabel: downloadLabel });
      });
    } catch(e){ try { console.warn('[Gallery] fallbackFromDom error', e); } catch(_e){} }
    return out;
  };

  Gallery.prototype.render = function(){
    var root = this.root;
    try { console.log('[Gallery] render start', { items: (this.data||[]).length }); } catch(e){}
    root.classList.add('masonry');
    var html = this.data.map(function(it, idx){
      var jpg = it.jpg || it.src || '';
      var webp = it.webp || ''; var jpgURL = safeURL(jpg); var webpURL = safeURL(webp);
      var alt = it.alt || (it.title || (jpg.split('/').pop())) || 'Photo';
      var title = it.title || '';
      var desc = it.desc || '';
      var download = it.download || jpg;
      //console.log("Rendering", it);
      return (
        '<article class="masonry-item gallery-item">\n'
        //+'<picture><source type="image/webp" srcset="'+webp+'"><img src="'+webp+'" alt="'+alt+'" loading="lazy"></picture>\n'

        + (webpURL?'<picture><source type="image/webp" srcset="'+webpURL+'"><img src="'+jpgURL+'" alt="'+alt+'" loading="lazy"></picture>':'<img src="'+jpgURL+'" alt="'+alt+'" loading="lazy">')
        + '\n  <div class="gallery-overlay">\n'
        + '    <div class="gallery-content">\n'
        + (title?('      <h3 class="text-xl  font-bold mb-2">'+title+'</h3>'):'')
        + (desc?('      <p class="text-sm ">'+desc+'</p>'):'')
        + '      <div class="gallery-zoom">\n'
        + '        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" data-lucide="zoom-in" class="lucide lucide-zoom-in h-6 w-6"><circle cx="11" cy="11" r="8"></circle><line x1="21" x2="16.65" y1="21" y2="16.65"></line><line x1="11" x2="11" y1="8" y2="14"></line><line x1="8" x2="14" y1="11" y2="11"></line></svg>\n'
        + '        <span class="">Kliknij aby zobaczyć</span>\n'
        + '        <a target="_blank" href="'+download+'" download class="ml-4 text-sm underline">' + (it && it.downloadLabel?it.downloadLabel:'Pobierz') + '</a>\n'
        + '      </div>\n'
        + '    </div>\n'
        + '  </div>\n'
        + '</article>'
      );
    }).join('');
    root.innerHTML = html;
    try { console.log('[Gallery] render done', { childCount: root.children.length }); } catch(e){}

    // Click -> modal
    root.addEventListener('click', function(e){
      var item = e.target.closest('.gallery-item');
      if (!item) return;
      var img = item.querySelector('img');
      var src = img ? img.getAttribute('src') : '';
      try { console.log('[Gallery] item click', { src: src }); } catch(e){}
      if (src && typeof window.openGalleryModal === 'function'){
        window.openGalleryModal(src);
      }
    });
  };

  return Gallery;
}));




