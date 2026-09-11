document.querySelectorAll('[data-purchase-assistant][data-purchase-assistant-context="checkout"]').forEach(assistant=>{
  const form=document.querySelector('[data-checkout]');
  if(!form)return;
  const title=assistant.querySelector('[data-assistant-title]');
  const message=assistant.querySelector('[data-assistant-message]');
  const action=assistant.querySelector('[data-assistant-action]');
  const progress=assistant.querySelector('[data-assistant-progress]');
  const stepKeys=['account','profile','address','shipping','payment'];
  const stepNodes=Object.fromEntries(stepKeys.map(key=>[key,assistant.querySelector(`[data-assistant-step="${key}"]`)]));
  const shippingComplete=()=>{
    const stores=[...form.querySelectorAll('[data-shipping-store]')];
    return stores.length>0&&stores.every(store=>!!store.querySelector('input[type="radio"]:checked'));
  };
  const setTone=tone=>{
    assistant.classList.remove('purchase-assistant--info','purchase-assistant--attention','purchase-assistant--success');
    assistant.classList.add(`purchase-assistant--${tone}`);
  };
  const setCopy=(tone,nextTitle,nextMessage,label,href)=>{
    setTone(tone);
    if(title)title.textContent=nextTitle;
    if(message)message.textContent=nextMessage;
    if(action){
      action.replaceChildren(document.createTextNode(`${label} `));
      const arrow=document.createElement('span');arrow.setAttribute('aria-hidden','true');arrow.textContent='→';action.append(arrow);action.href=href;
    }
  };
  const setSteps=states=>{
    const firstPending=stepKeys.find(key=>!states[key]);
    let completed=0;
    stepKeys.forEach(key=>{
      const node=stepNodes[key];if(!node)return;
      const done=!!states[key];if(done)completed++;
      node.classList.toggle('is-done',done);
      node.classList.toggle('is-current',!done&&key===firstPending);
      node.classList.toggle('is-pending',!done&&key!==firstPending);
      const icon=node.querySelector('i');if(icon)icon.textContent=done?'✓':String(stepKeys.indexOf(key)+1);
    });
    if(progress)progress.style.width=`${Math.round(completed/stepKeys.length*100)}%`;
  };
  const lockForProfile=locked=>{
    const submit=form.querySelector('[data-checkout-submit]');
    const mobile=document.querySelector('[data-mobile-submit]');
    if(locked){
      if(submit){submit.disabled=true;submit.classList.remove('button--primary','is-ready');submit.classList.add('button--secondary');submit.textContent='Complete seus dados para continuar'}
      if(mobile){mobile.disabled=true;mobile.classList.remove('button--primary');mobile.classList.add('button--secondary');mobile.textContent='Completar dados'}
    }
  };
  const sync=()=>{
    const customer=form.dataset.customer==='1';
    const profileComplete=form.dataset.profileComplete==='1';
    const hasAddress=!!form.querySelector('[name="address_id"]:checked');
    const delivery=shippingComplete();
    const paymentConfigured=form.dataset.paymentConfigured==='1';
    const shippingConfigured=form.dataset.shippingConfigured==='1';
    const payment=!!form.querySelector('[name="payment_method"]:checked');
    const terms=!!form.querySelector('[name="terms"]:checked');
    const paymentDone=payment&&terms&&paymentConfigured;
    setSteps({account:customer,profile:customer&&profileComplete,address:hasAddress,shipping:delivery,payment:paymentDone});
    lockForProfile(customer&&!profileComplete);

    if(!customer){setCopy('info','Vamos continuar de onde você parou','Entre ou crie sua conta. Seu carrinho continua salvo e você volta direto para esta etapa.','Entrar ou criar conta',assistant.dataset.loginUrl);return}
    if(!profileComplete){setCopy('attention','Só faltam seus dados de compra','Complete CPF/CNPJ e telefone com DDD. Depois de salvar, você volta automaticamente para o checkout.','Completar meus dados',assistant.dataset.profileUrl);return}
    if(!hasAddress){setCopy('attention','Agora escolha onde receber','Cadastre seu endereço. Ao informar o CEP, preenchemos automaticamente os dados disponíveis.','Adicionar endereço',assistant.dataset.addressUrl);return}
    if(!shippingConfigured){setCopy('attention','A entrega está temporariamente indisponível','Seu carrinho está salvo. Você não precisa refazer a compra; tente novamente em alguns instantes.','Voltar ao carrinho',assistant.dataset.cartUrl);return}
    if(!delivery){setCopy('attention','Escolha a entrega para continuar','Selecione uma modalidade de entrega para cada loja do seu carrinho.','Ver opções de entrega','#checkout-shipping');return}
    if(!paymentConfigured){setCopy('attention','O pagamento está temporariamente indisponível','Seu carrinho e sua entrega continuam salvos. Tente finalizar novamente em alguns instantes.','Voltar ao carrinho',assistant.dataset.cartUrl);return}
    if(!payment){setCopy('info','Escolha como prefere pagar','Selecione Pix, cartão ou boleto para seguir.','Escolher pagamento','#checkout-payment');return}
    if(!terms){setCopy('info','Último passo: confirme os termos','Revise o resumo e confirme os termos da compra para liberar o botão de finalização.','Revisar e confirmar','#checkout-summary');return}
    setCopy('success','Tudo pronto para finalizar','Revise o total e toque em Finalizar compra. Seu pedido será criado com segurança.','Ir para finalizar','#checkout-summary');
  };
  form.addEventListener('submit',event=>{
    if(form.dataset.customer==='1'&&form.dataset.profileComplete!=='1'){
      event.preventDefault();event.stopImmediatePropagation();window.location.href=assistant.dataset.profileUrl;
    }
  },true);
  form.addEventListener('change',()=>queueMicrotask(sync));
  const shippingRoot=form.querySelector('[data-shipping-groups]');
  if(shippingRoot)new MutationObserver(sync).observe(shippingRoot,{subtree:true,childList:true,attributes:true,attributeFilter:['checked']});
  sync();
});
