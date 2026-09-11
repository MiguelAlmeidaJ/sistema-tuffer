(()=>{
  'use strict';

  document.querySelectorAll('[data-checkout-card]').forEach(form=>{
    const number=form.querySelector('[data-card-number]');
    const holder=form.querySelector('[data-card-holder]');
    const expiry=form.querySelector('[data-card-expiry]');
    const cvv=form.querySelector('[data-card-cvv]');
    const tokenField=form.querySelector('[name="card_token"]');
    const installments=form.querySelector('[name="card_installments"]');
    const status=form.querySelector('[data-card-status]');
    const scanRoot=form.querySelector('[data-card-scan]');
    const scanInput=form.querySelector('[data-card-camera-input]');
    const scanButton=form.querySelector('[data-card-camera-button]');
    if(!number||!holder||!expiry||!cvv||!tokenField)return;

    const digits=value=>String(value||'').replace(/\D/g,'');
    const money=value=>Number(value||0).toLocaleString('pt-BR',{style:'currency',currency:'BRL'});
    const selectedCard=()=>form.querySelector('[name="payment_method"]:checked')?.value==='card';
    const setStatus=(message,tone='')=>{
      if(!status)return;
      status.textContent=message;
      status.classList.toggle('is-error',tone==='error');
      status.classList.toggle('is-success',tone==='success');
    };
    const clearToken=()=>{tokenField.value=''};
    const formatNumber=value=>digits(value).slice(0,19).replace(/(.{4})/g,'$1 ').trim();
    const formatExpiry=value=>{
      const raw=digits(value).slice(0,6);
      if(raw.length<=2)return raw;
      return `${raw.slice(0,2)}/${raw.slice(2,6)}`;
    };
    const luhn=value=>{
      const raw=digits(value);
      if(raw.length<13||raw.length>19)return false;
      let sum=0,alternate=false;
      for(let i=raw.length-1;i>=0;i--){
        let n=Number(raw[i]);
        if(alternate){n*=2;if(n>9)n-=9}
        sum+=n;alternate=!alternate;
      }
      return sum%10===0;
    };
    const expiryParts=()=>{
      const match=expiry.value.trim().match(/^(0[1-9]|1[0-2])\/(\d{2}|\d{4})$/);
      if(!match)return null;
      let year=Number(match[2]);
      if(match[2].length===2)year+=2000;
      const month=Number(match[1]);
      const now=new Date();
      const validUntil=new Date(year,month,0,23,59,59);
      return validUntil>=now?{month,year}:null;
    };
    const validate=()=>{
      const exp=expiryParts();
      if(!luhn(number.value)){number.setCustomValidity('Confira o número do cartão.');return false}
      number.setCustomValidity('');
      if(holder.value.trim().length<3){holder.setCustomValidity('Informe o nome como está no cartão.');return false}
      holder.setCustomValidity('');
      if(!exp){expiry.setCustomValidity('Informe uma validade futura no formato MM/AA.');return false}
      expiry.setCustomValidity('');
      if(!/^\d{3,4}$/.test(digits(cvv.value))){cvv.setCustomValidity('Informe o código de segurança com 3 ou 4 dígitos.');return false}
      cvv.setCustomValidity('');
      return true;
    };
    const updateInstallments=()=>{
      if(!installments)return;
      const summary=form.querySelector('[data-summary-total]');
      const base=Number(summary?.dataset.baseTotal||0);
      const shipping=[...form.querySelectorAll('[data-shipping-price]:checked')]
        .reduce((sum,input)=>sum+Number(input.dataset.shippingPrice||0),0);
      const total=base+shipping;
      [...installments.options].forEach(option=>{
        const count=Math.max(1,Number(option.value)||1);
        option.textContent=`${count}x de ${money(total/count)} sem juros`;
      });
    };

    number.addEventListener('input',()=>{number.value=formatNumber(number.value);number.setCustomValidity('');clearToken()});
    holder.addEventListener('input',()=>{holder.value=holder.value.replace(/[^A-Za-zÀ-ÿ '\-]/g,'').slice(0,64);holder.setCustomValidity('');clearToken()});
    expiry.addEventListener('input',()=>{expiry.value=formatExpiry(expiry.value);expiry.setCustomValidity('');clearToken()});
    cvv.addEventListener('input',()=>{cvv.value=digits(cvv.value).slice(0,4);cvv.setCustomValidity('');clearToken()});
    form.addEventListener('change',event=>{if(event.target.matches?.('[data-shipping-price],[name="address_id"]'))updateInstallments()});
    const shippingRoot=form.querySelector('[data-shipping-groups]');
    if(shippingRoot)new MutationObserver(updateInstallments).observe(shippingRoot,{subtree:true,childList:true});
    updateInstallments();

    const tokenize=async()=>{
      if(form.dataset.cardConfigured!=='1')throw new Error('O pagamento por cartão ainda não está habilitado.');
      const publicKey=form.dataset.pagarmePublicKey||'';
      const endpoint=form.dataset.cardTokenUrl||'';
      if(!/^pk_(?:test_)?[A-Za-z0-9_-]+$/.test(publicKey)||!endpoint)throw new Error('A chave pública do pagamento não está configurada.');
      const exp=expiryParts();
      if(!exp)throw new Error('Confira a validade do cartão.');
      const response=await fetch(`${endpoint}?appId=${encodeURIComponent(publicKey)}`,{
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify({
          type:'card',
          card:{
            number:digits(number.value),
            holder_name:holder.value.trim(),
            exp_month:exp.month,
            exp_year:exp.year,
            cvv:digits(cvv.value),
          },
        }),
      });
      const data=await response.json().catch(()=>({}));
      if(!response.ok||typeof data.id!=='string'||!/^token_[A-Za-z0-9_-]+$/.test(data.id)){
        const message=typeof data.message==='string'?data.message:'Não foi possível validar o cartão agora.';
        throw new Error(message);
      }
      tokenField.value=data.id;
      return data.id;
    };

    form.addEventListener('submit',async event=>{
      if(!selectedCard()||tokenField.value)return;
      event.preventDefault();
      event.stopImmediatePropagation();
      if(!validate()){
        [number,holder,expiry,cvv].find(field=>!field.checkValidity())?.reportValidity();
        setStatus('Confira os dados do cartão antes de continuar.','error');
        return;
      }
      const submit=form.querySelector('[data-checkout-submit]');
      const mobile=document.querySelector('[data-mobile-submit]');
      if(submit){submit.disabled=true;submit.textContent='Validando cartão…'}
      if(mobile){mobile.disabled=true;mobile.textContent='Validando…'}
      setStatus('Validando o cartão de forma segura…');
      try{
        await tokenize();
        setStatus('Cartão validado. Finalizando seu pedido…','success');
        form.requestSubmit();
      }catch(error){
        clearToken();
        const message=error instanceof Error?error.message:'Não foi possível validar o cartão agora.';
        setStatus(message,'error');
        if(submit){submit.disabled=false;submit.textContent='Tentar finalizar novamente'}
        if(mobile){mobile.disabled=false;mobile.textContent='Tentar novamente'}
      }
    },true);

    const parseScannedText=text=>{
      const cardCandidates=(text.match(/(?:\d[\s-]?){13,19}/g)||[])
        .map(value=>digits(value))
        .filter(value=>value.length>=13&&value.length<=19&&luhn(value));
      if(cardCandidates[0])number.value=formatNumber(cardCandidates[0]);
      const exp=text.match(/(?:^|\D)(0[1-9]|1[0-2])\s*[\/-]\s*(\d{2}|\d{4})(?:\D|$)/);
      if(exp)expiry.value=`${exp[1]}/${exp[2].length===4?exp[2].slice(-2):exp[2]}`;
      clearToken();
      return Boolean(cardCandidates[0]||exp);
    };

    const mobile=matchMedia('(max-width: 760px), (pointer: coarse)');
    const detectorAvailable=typeof window.TextDetector==='function';
    if(scanRoot)scanRoot.hidden=!(mobile.matches&&detectorAvailable&&scanInput);
    scanButton?.addEventListener('click',()=>scanInput?.click());
    scanInput?.addEventListener('change',async()=>{
      const file=scanInput.files?.[0];
      if(!file)return;
      if(typeof window.TextDetector!=='function'){
        setStatus('A leitura automática não está disponível neste navegador. Digite os dados do cartão.','error');
        return;
      }
      setStatus('Lendo o cartão no próprio aparelho…');
      const url=URL.createObjectURL(file);
      try{
        const image=new Image();
        image.src=url;
        await image.decode();
        const detected=await new TextDetector().detect(image);
        const text=detected.map(item=>item.rawValue||'').join(' ');
        if(parseScannedText(text))setStatus('Dados visíveis preenchidos. Confira e informe o CVV manualmente.','success');
        else setStatus('Não conseguimos identificar o número. Tente outra foto ou digite os dados.','error');
      }catch{
        setStatus('Não conseguimos ler essa foto. Tente novamente ou digite os dados.','error');
      }finally{
        URL.revokeObjectURL(url);
        scanInput.value='';
      }
    });
  });
})();
