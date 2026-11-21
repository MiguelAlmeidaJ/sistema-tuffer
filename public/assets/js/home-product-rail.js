(() => {
'use strict';
document.querySelectorAll('[data-product-rail]').forEach(rail=>{
  const track=rail.querySelector('[data-product-track]');
  const prev=rail.querySelector('[data-product-previous]');
  const next=rail.querySelector('[data-product-next]');
  const progress=rail.querySelector('[data-product-progress]');
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
    if(progress){const maxScroll=Math.max(1,track.scrollWidth-track.clientWidth);const fraction=Math.max(0,Math.min(1,track.scrollLeft/maxScroll));const width=Math.min(100,100*visible/Math.max(visible,count));progress.style.width=width+'%';progress.style.transform='translateX('+(fraction*(100/Math.max(width/100,.01)-100))+'%)';}
    if(prev&&next) prev.parentElement.hidden=count<=visible;
  };
  const move=direction=>{
    const {index,step,visible}=position();
    const max=Math.max(0,cards().length-visible);
    const destination=Math.max(0,Math.min(max,index+direction));
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
  // Autoplay suave somente enquanto o visitante não estiver interagindo.
  const reducedMotion=matchMedia('(prefers-reduced-motion:reduce)');
  let timer=0;
  const stop=()=>{if(timer){clearInterval(timer);timer=0}};
  const start=()=>{
    stop();
    if(reducedMotion.matches||document.hidden||cards().length<=position().visible)return;
    timer=setInterval(()=>{
      const {index,visible}=position();
      const max=Math.max(0,cards().length-visible);
      if(index>=max)track.scrollTo({left:0,behavior:'smooth'});
      else move(1);
    },6800);
  };
  rail.addEventListener('pointerenter',stop);
  rail.addEventListener('pointerleave',start);
  rail.addEventListener('focusin',stop);
  rail.addEventListener('focusout',start);
  track.addEventListener('pointerdown',stop,{passive:true});
  track.addEventListener('touchstart',stop,{passive:true});
  track.addEventListener('touchend',start,{passive:true});
  document.addEventListener('visibilitychange',()=>document.hidden?stop():start());
  reducedMotion.addEventListener?.('change',start);
  refresh();start();
});
})();