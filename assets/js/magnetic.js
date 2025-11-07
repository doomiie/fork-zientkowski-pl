// Lightweight magnetic hover effect for cards
(function(){
  function initMagnetic(selector){
    var els = document.querySelectorAll(selector);
    els.forEach(function(el){
      el.style.willChange = 'transform';
      if (!el.style.transition) el.style.transition = 'transform 0.2s ease';
      var frame; var targetX = 0, targetY = 0, curX = 0, curY = 0;
      function animate(){
        curX += (targetX - curX) * 0.25;
        curY += (targetY - curY) * 0.25;
        el.style.transform = 'translate3d(' + curX.toFixed(2) + 'px,' + curY.toFixed(2) + 'px,0)';
        if (Math.abs(targetX - curX) > 0.2 || Math.abs(targetY - curY) > 0.2) {
          frame = requestAnimationFrame(animate);
        } else {
          cancelAnimationFrame(frame);
          frame = null;
        }
      }
      el.addEventListener('mousemove', function(e){
        var rect = el.getBoundingClientRect();
        var x = e.clientX - rect.left - rect.width/2;
        var y = e.clientY - rect.top - rect.height/2;
        targetX = x * 0.06; // intensity
        targetY = y * 0.06;
        if (!frame) frame = requestAnimationFrame(animate);
      });
      el.addEventListener('mouseleave', function(){
        targetX = 0; targetY = 0; if (!frame) frame = requestAnimationFrame(animate);
      });
    });
  }

  function onReady(){
    initMagnetic('.morphing-card, .magnetic-card');
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', onReady); else onReady();
})();

