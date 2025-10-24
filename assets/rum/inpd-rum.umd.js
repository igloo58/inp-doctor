(function(){
  var C=(window.INPD_CFG||{}),q=[],timer=null;
  function send(){var p={token:C.token,events:q.splice(0,q.length)};if(!p.events.length)return;
    if(navigator.sendBeacon){navigator.sendBeacon(C.endpoint,new Blob([JSON.stringify(p)],{type:'application/json'}));return;}
    fetch(C.endpoint,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(p)});}
  function schedule(){if(timer)return;timer=setTimeout(function(){timer=null;send();},3000);}
  addEventListener('click',function(e){try{q.push({t:Math.floor(Date.now()/1000),u:location.pathname+location.search,type:e.type||'click',sel:(e.target&&e.target.id)?('#'+e.target.id):(e.target&&e.target.tagName)||'',inp:0,lt:0,src:'',ua:'',dev:'other',sr:C.sample||100});schedule();}catch(_){}},{passive:true,once:true});
})();
