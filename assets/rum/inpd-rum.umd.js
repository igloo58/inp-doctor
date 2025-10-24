(function(){
  'use strict';
  var C = (window.INPD_CFG || {});
  var max = null;
  var lastSel = '';

  function sel(el){
    if (!el || !el.nodeType) return '';
    if (el.id) return '#'+el.id;
    var t = (el.tagName || '').toLowerCase();
    var c = (el.className && typeof el.className === 'string') ? ('.' + el.className.trim().split(/\s+/).slice(0,2).join('.')) : '';
    return t + c;
  }

  // Track last clicked selector lightly
  addEventListener('click', function(e){
    try { lastSel = sel(e.target); } catch(_){ }
  }, {passive:true});

  // Use Event Timing if available to approximate INP
  try {
    var po = new PerformanceObserver(function(list){
      list.getEntries().forEach(function(e){
        if (!e || e.entryType !== 'event') return;
        if (e.name !== 'click' && e.name !== 'keydown' && e.name !== 'pointerdown') return;
        var dur = Math.round(e.duration || 0);
        if (!max || dur > max.inp) {
          max = {
            t: Date.now(),
            type: e.name,
            sel: lastSel || '',
            inp: dur
          };
        }
      });
    });
    po.observe({type:'event', buffered:true, durationThreshold:16});
  } catch(_){ }

  function payload(){
    if (!max) return null;
    var dev = /Mobi|Android|iPhone|iPad/.test(navigator.userAgent) ? 'mobile' : 'desktop';
    return {
      token: C.token,
      events: [{
        t: Math.floor(max.t/1000),
        u: location.pathname + location.search,
        type: max.type || 'click',
        sel: max.sel || '',
        inp: max.inp || 0,
        lt: 0,
        src: '',
        ua: '',
        dev: dev,
        sr: C.sample || 100
      }]
    };
  }

  function send(){
    var p = payload();
    if (!p) return;
    var body = JSON.stringify(p);
    if (navigator.sendBeacon) {
      try { navigator.sendBeacon(C.endpoint, new Blob([body], {type:'application/json'})); return; } catch(_){ }
    }
    try {
      fetch(C.endpoint, {method:'POST', headers:{'Content-Type':'application/json'}, body});
    } catch(_){ }
  }

  addEventListener('visibilitychange', function(){ if (document.visibilityState === 'hidden') send(); });
  addEventListener('pagehide', send);
})();
