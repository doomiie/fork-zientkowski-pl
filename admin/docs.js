(function(){
  const form = document.getElementById('uploadForm');
  const wrap = document.getElementById('progressWrap');
  const bar = document.getElementById('progressBar');
  const txt = document.getElementById('progressText');
  const log = document.getElementById('progressLog');
  if (!form) return;
  form.addEventListener('submit', function(e){
    e.preventDefault();
    const fd = new FormData(form);
    fd.append('ajax', '1');
    if (wrap) wrap.classList.remove('hidden');
    if (bar) bar.style.width = '0%';
    if (txt) txt.textContent = '0%';
    if (log) log.textContent = '';
    const xhr = new XMLHttpRequest();
    xhr.open('POST', form.getAttribute('action') || 'docs.php');
    xhr.responseType = 'json';
    xhr.upload.onprogress = function(ev){
      if (ev.lengthComputable) {
        const p = Math.round((ev.loaded/ev.total)*100);
        if (bar) bar.style.width = p + '%';
        if (txt) txt.textContent = p + '%';
        console.log('[upload] progress', p+'%', ev.loaded, '/', ev.total);
      }
    };
    xhr.onreadystatechange = function(){
      if (xhr.readyState === 4) {
        console.log('[upload] done', xhr.status, xhr.response);
        if (xhr.status >= 200 && xhr.status < 300) {
          if (txt) txt.textContent = 'Zakończono';
          setTimeout(function(){ window.location.reload(); }, 500);
        } else {
          if (txt) txt.textContent = 'Błąd';
          try {
            if (log) log.textContent = (xhr.response && (xhr.response.error||xhr.response.message)) || 'Upload failed';
          } catch(_){ if (log) log.textContent = 'Upload failed'; }
        }
      }
    };
    xhr.onerror = function(){ console.log('[upload] error'); if (txt) txt.textContent = 'Błąd'; };
    xhr.send(fd);
  });
})();

