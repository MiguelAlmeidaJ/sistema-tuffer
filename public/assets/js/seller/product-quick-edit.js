(()=>{'use strict';
  const dialogs={
    price:document.querySelector('[data-product-quick-dialog="price"]'),
    dimensions:document.querySelector('[data-product-quick-dialog="dimensions"]')
  };
  const fill=(form,key,value)=>{const input=form.elements.namedItem(key);if(input)input.value=value??''};
  const launch=(button,type)=>{
    const dialog=dialogs[type];if(!dialog||typeof dialog.showModal!=='function')return;
    const form=dialog.querySelector('[data-product-quick-form]');
    form.action=button.dataset.quickUrl;
    dialog.querySelector('[data-product-quick-name]').textContent=button.dataset.quickName||'Produto';
    if(type==='price'){
      fill(form,'price',button.dataset.quickPrice);
      fill(form,'promotional_price',button.dataset.quickPromo);
      fill(form,'wholesale_price',button.dataset.quickWholesale);
      const wholesale=dialog.querySelector('[data-product-quick-wholesale-field]');
      wholesale.hidden=button.dataset.quickWholesaleEnabled!=='1';
      const field=form.elements.namedItem('wholesale_price');
      if(field)field.disabled=wholesale.hidden;
    }else{
      ['weight','width','height','length'].forEach(field=>fill(form,field,button.dataset['quick'+field[0].toUpperCase()+field.slice(1)]));
    }
    dialog.showModal();
    const first=form.querySelector('input:not([type="hidden"]):not(:disabled)');
    first?.focus({preventScroll:true});
  };
  document.querySelectorAll('[data-product-quick-price]').forEach(button=>button.addEventListener('click',()=>launch(button,'price')));
  document.querySelectorAll('[data-product-quick-dimensions]').forEach(button=>button.addEventListener('click',()=>launch(button,'dimensions')));
  Object.values(dialogs).forEach(dialog=>{
    if(!dialog)return;
    dialog.querySelectorAll('[data-product-quick-close]').forEach(button=>button.addEventListener('click',()=>dialog.close()));
    dialog.addEventListener('click',event=>{if(event.target===dialog)dialog.close()});
    const form=dialog.querySelector('form');
    form?.addEventListener('submit',event=>{
      if(dialog.dataset.productQuickDialog==='price'){
        const regular=Number(form.elements.namedItem('price')?.value||0);
        const promoInput=form.elements.namedItem('promotional_price');
        const promo=promoInput?.value?Number(promoInput.value):null;
        if(promo!==null && promo>=regular){event.preventDefault();promoInput.setCustomValidity('O preço promocional deve ser menor que o preço normal.');promoInput.reportValidity();return}
        if(promoInput)promoInput.setCustomValidity('');
      }
      if(!form.checkValidity()){event.preventDefault();form.reportValidity();return}
      const submit=form.querySelector('button[type="submit"]');
      if(submit){submit.disabled=true;submit.textContent='Salvando...'}
    });
    dialog.querySelector('input[name="promotional_price"]')?.addEventListener('input',event=>event.currentTarget.setCustomValidity(''));
    dialog.addEventListener('close',()=>{const submit=form?.querySelector('button[type="submit"]');if(submit){submit.disabled=false;submit.textContent=dialog.dataset.productQuickDialog==='price'?'Salvar preços':'Salvar dimensões'}});
  });
})();