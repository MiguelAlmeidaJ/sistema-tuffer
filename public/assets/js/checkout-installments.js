(()=>{
  'use strict';

  const form=document.querySelector('[data-checkout][data-checkout-card]');
  if(!form)return;
  const select=form.querySelector('[name="card_installments"]');
  if(!select)return;

  const moneyCents=cents=>(Number(cents||0)/100).toLocaleString('pt-BR',{style:'currency',currency:'BRL'});
  const baseCents=()=>{
    const summary=form.querySelector('[data-summary-total]');
    const base=Math.round(Number(summary?.dataset.baseTotal||0)*100);
    const shipping=[...form.querySelectorAll('[data-shipping-price]:checked')]
      .reduce((sum,input)=>sum+Math.round(Number(input.dataset.shippingPrice||0)*100),0);
    return Math.max(0,base+shipping);
  };
  const isCard=()=>form.querySelector('[name="payment_method"]:checked')?.value==='card';
  let configuration=null;

  const pricingEndpoint=()=>{
    const source=form.dataset.quotesUrl||'/checkout/cotacoes';
    const url=new URL(source,location.origin);
    url.pathname=url.pathname.replace(/\/cotacoes\/?$/,'/parcelamento');
    return url.toString();
  };
  const freeInstallments=()=>Math.max(1,Number(configuration?.free_installments)||6);
  const maxInstallments=()=>Math.max(freeInstallments(),Number(configuration?.max_installments)||6);
  const ensureOptions=()=>{
    const existing=new Set([...select.options].map(option=>Number(option.value)||0));
    for(let count=1;count<=maxInstallments();count++){
      if(existing.has(count))continue;
      const option=document.createElement('option');
      option.value=String(count);
      select.append(option);
    }
    [...select.options].forEach(option=>{
      const count=Number(option.value)||0;
      if(count>maxInstallments())option.remove();
    });
  };
  const planAvailable=installments=>{
    if(installments<=freeInstallments())return true;
    return Boolean(configuration?.plans?.[installments]?.available);
  };
  const rate=installments=>{
    const value=configuration?.rates?.[installments];
    return value===null||value===undefined||value===''?null:Number(value);
  };
  const quote=(amount,installments)=>{
    const free=freeInstallments();
    if(installments<=free)return {surcharge:0,total:amount};
    const baseRate=rate(free),targetRate=rate(installments);
    if(baseRate===null||targetRate===null||targetRate<baseRate||targetRate>=100)return null;
    const target=targetRate/100;
    const extra=(targetRate-baseRate)/100;
    const surcharge=Math.max(0,Math.ceil((amount*extra)/(1-target)));
    return {surcharge,total:amount+surcharge};
  };
  const surchargeRow=()=>{
    const summary=form.querySelector('.checkout-summary');
    const list=summary?.querySelector('dl');
    if(!list)return null;
    let row=list.querySelector('[data-card-installment-surcharge-row]');
    if(!row){
      row=document.createElement('div');
      row.dataset.cardInstallmentSurchargeRow='';
      row.hidden=true;
      const label=document.createElement('dt');label.textContent='Acréscimo do parcelamento';
      const value=document.createElement('dd');value.dataset.cardInstallmentSurcharge='';
      row.append(label,value);list.append(row);
    }
    return row;
  };
  const render=()=>{
    ensureOptions();
    const amount=baseCents();
    const free=freeInstallments();
    const max=maxInstallments();
    [...select.options].forEach(option=>{
      const count=Math.max(1,Number(option.value)||1);
      const pricing=quote(amount,count);
      option.disabled=!planAvailable(count);
      if(count<=free){
        option.textContent=`${count}x de ${moneyCents(amount/count)} sem acréscimo`;
      }else if(pricing){
        option.textContent=`${count}x de ${moneyCents(pricing.total/count)} · acréscimo ${moneyCents(pricing.surcharge)}`;
      }else{
        option.textContent=`${count}x · taxa Pagar.me não configurada`;
      }
    });
    if(select.selectedOptions[0]?.disabled)select.value=String(Math.min(free,max));

    const cardLabel=form.querySelector('[name="payment_method"][value="card"]')?.closest('label')?.querySelector('small');
    if(cardLabel)cardLabel.textContent=max>free
      ? `Até ${free}x sem acréscimo · até ${max}x com custo adicional da Pagar.me`
      : `Até ${free}x sem acréscimo`;

    const count=Math.max(1,Number(select.value)||1);
    const pricing=isCard()?quote(amount,count):{surcharge:0,total:amount};
    const effective=pricing||{surcharge:0,total:amount};
    const row=surchargeRow();
    if(row){
      row.hidden=!isCard()||effective.surcharge<1;
      const value=row.querySelector('[data-card-installment-surcharge]');
      if(value)value.textContent=`+ ${moneyCents(effective.surcharge)}`;
    }
    const total=form.querySelector('[data-summary-total]');
    if(total)total.textContent=moneyCents(effective.total);
    document.querySelectorAll('[data-mobile-total]').forEach(item=>item.textContent=moneyCents(effective.total));
    const installment=form.querySelector('[data-installment]');
    if(installment){
      if(isCard()){
        installment.textContent=count<=free
          ? `${count}x de ${moneyCents(amount/count)} sem acréscimo`
          : pricing
            ? `${count}x de ${moneyCents(pricing.total/count)} · acréscimo total ${moneyCents(pricing.surcharge)}`
            : `Parcelamento acima de ${free}x indisponível`;
      }else{
        installment.textContent=`ou ${free}x de ${moneyCents(amount/free)} sem acréscimo`;
      }
    }
  };

  select.addEventListener('change',render);
  form.addEventListener('change',()=>queueMicrotask(render));
  const shipping=form.querySelector('[data-shipping-groups]');
  if(shipping)new MutationObserver(()=>queueMicrotask(render)).observe(shipping,{subtree:true,childList:true});

  fetch(pricingEndpoint(),{headers:{'Accept':'application/json'}})
    .then(response=>response.ok?response.json():Promise.reject(new Error('pricing unavailable')))
    .then(data=>{configuration=data;render()})
    .catch(()=>{configuration={free_installments:6,max_installments:6,rates:{},plans:{}};render()});
})();
