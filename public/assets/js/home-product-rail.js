(() => {
'use strict';
document.querySelectorAll('[data-product-rail]').forEach(rail=>{
  const track=rail.querySelector('[data-product-track]');
  const prev=rail.querySelector('[data-product-previous]');
  const next=rail.querySelector('[data-product-next]');
  const counter=rail.querySelector('[data-product-counter]');
  if(!track) return;
  const cards=()=>[...track.querySelectorAll('.product-card')];
  const position=()=>{
    const first=cards()[0];
    if(!first) return {index:0,step:0,visible:1};
    const gap=parseFloat(getComputedStyle(track).columnGap || getComputedStyle(track).gap)||0;
    const step=first.getBoundingClientRect().width+gap;
    const visible=Math.max(1,Math.round((track.clientWidth+gap)/step));
    return {index:Math.round(track.scrollLeft/Math.max(step,1)),step,visible};
  };
  const refresh=()=>{
    const {index,visible}=position();
    const count=cards().length;
    const max=Math.max(0,count-visible);
    if(prev) prev.disabled=index<=0;
    if(next) next.disabled=index>=max;
    if(counter) counter.textContent=count>visible?Math.min(index+1,count)+'–'+Math.min(index+visible,count)+' de '+count+' produtos':'';
    if(prev&&next) prev.parentElement.hidden=count<=visible;
  };
  const move=direction=>{
    const {index,step,visible}=position();
    const max=Math.max(0,cards().length-visible);
    const destination=Math.max(0,Math.min(max,index+direction*visible));
    track.scrollTo({left:destination*step,behavior:matchMedia('(prefers-reduced-motion:reduce)').matches?'auto':'smooth'});
    requestAnimationFrame(refresh);
  };
  prev?.addEventListener('click',()=>move(-1));
  next?.addEventListener('click',()=>move(1));
  track.addEventListener('scroll',refresh,{passive:true});
  track.addEventListener('keydown',event=>{
    if(event.key==='ArrowRight'){event.preventDefault();move(1)}
    if(event.key==='ArrowLeft'){event.preventDefault();move(-1)}
  });
  if('ResizeObserver' in window) new ResizeObserver(refresh).observe(track);
  else window.addEventListener('resize',refresh);
  refresh();
});
})();