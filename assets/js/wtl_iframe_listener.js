/*
  WTL payment iframe helper
  - Odbiera postMessage z rodzica i wypełnia pole e-mail na stronie płatności.
  - Obsługuje też query param prefill_email=z@x.pl jako fallback.

  Użycie (wstaw na stronie w iframie):
    <script src="/assets/js/wtl_iframe_listener.js"></script>
*/
(function(){
  function findEmailInput(){
    return (
      document.getElementById('wtl-email') ||
      document.querySelector('input[name="email" i]') ||
      document.querySelector('input[type="email"]') ||
      null
    );
  }

  function setEmail(val){
    if (!val) return;
    var el = findEmailInput();
    if (!el) return;
    try {
      el.value = val;
      el.dispatchEvent(new Event('input', { bubbles: true }));
      el.dispatchEvent(new Event('change', { bubbles: true }));
    } catch(_) {}
  }

  // Fallback: prefill from query string (?prefill_email=...)
  try {
    var params = new URLSearchParams(window.location.search);
    var q = params.get('prefill_email');
    if (q) setEmail(q);
  } catch(_) {}

  // Listen for messages from parent window
  window.addEventListener('message', function(e){
    var data = e && e.data ? e.data : {};
    if (typeof data !== 'object' || !data) return;
    if (data.wtlAction === 'prefill_email' && data.email) {
      setEmail(String(data.email).trim());
    }
  });
})();

