(function(){
  function hasCookie(){
    return document.cookie.split(';').some(c => c.trim().startsWith('vjs='));
  }
  if(!hasCookie()){
    const val = Math.floor(Math.random()*1e10).toString();
    const maxAge = 60*24*60*60; // 60 days
    document.cookie = `vjs=${val}; path=/; max-age=${maxAge}`;
  }
})();

