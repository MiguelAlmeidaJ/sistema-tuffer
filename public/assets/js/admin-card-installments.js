(()=>{
  'use strict';

  const financeInput=document.querySelector('#platform-settings-form input[name="section"][value="financeiro"]');
  const form=financeInput?.form;
  const root=document.querySelector('[data-platform-settings-page]');
  if(!form||!root)return;

  const endpoint=form.action.replace(/\/configuracoes\/?$/,'/configuracoes/parcelamento');
  const card=document.createElement('section');
  card.className='platform-settings-card';
  card.innerHTML=`
    <header><span>02</span><div><h3>Parcelamento no cartão</h3><p>Cadastre as taxas MDR contratadas com a Pagar.me. Até 3x o cliente não recebe acréscimo; de 4x a 6x ele paga somente o custo incremental em relação a 3x.</p></div></header>
    <div class="platform-fields platform-fields--three" data-card-rate-fields></div>
    <div class="settings-security-note"><span>i</span><p>Use as taxas reais do seu contrato. Se uma taxa de 4x, 5x ou 6x ficar em branco, essa opção será desabilitada no checkout. O cálculo compensa também a taxa incidente sobre o próprio acréscimo.</p></div>
    <div class="platform-settings__header-actions"><span data-card-rate-status aria-live="polite"></span><button class="button button--secondary" type="button" data-save-card-rates>Salvar taxas do parcelamento</button></div>`;
  form.insertAdjacentElement('afterend',card);

  const fields=card.querySelector('[data-card-rate-fields]');
  [3,4,5,6].forEach(count=>{
    const label=document.createElement('label');
    label.className='platform-field';
    const title=document.createTextNode(`${count}x · MDR contratado (%)`);
    const input=document.createElement('input');
    input.type='number';input.min='0';input.max='99.9999';input.step='0.0001';
    input.name=`pagarme_card_mdr_${count}x`;input.placeholder=count===3?'Ex.: taxa-base de 3x':'Deixe vazio para desabilitar';
    const small=document.createElement('small');
    small.textContent=count===3?'Referência usada para calcular somente o custo adicional acima de 3x.':`Taxa total cobrada pela Pagar.me em ${count}x.`;
    label.append(title,input,small);fields?.append(label);
  });

  const status=card.querySelector('[data-card-rate-status]');
  const setStatus=(message,error=false)=>{if(status){status.textContent=message;status.classList.toggle('is-error',error)}};
  const populate=configuration=>{
    [3,4,5,6].forEach(count=>{
      const input=card.querySelector(`[name="pagarme_card_mdr_${count}x"]`);
      const value=configuration?.rates?.[count];
      if(input)input.value=value===null||value===undefined?'':String(value);
    });
  };

  fetch(endpoint,{headers:{'Accept':'application/json'}})
    .then(response=>response.ok?response.json():Promise.reject(new Error()))
    .then(data=>populate(data.configuration))
    .catch(()=>setStatus('Não foi possível carregar as taxas atuais.',true));

  card.querySelector('[data-save-card-rates]')?.addEventListener('click',async event=>{
    const button=event.currentTarget;
    const body=new FormData();
    body.set('_token',form.querySelector('[name="_token"]')?.value||'');
    [3,4,5,6].forEach(count=>body.set(`pagarme_card_mdr_${count}x`,card.querySelector(`[name="pagarme_card_mdr_${count}x"]`)?.value||''));
    button.disabled=true;setStatus('Salvando…');
    try{
      const response=await fetch(endpoint,{method:'POST',body,headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}});
      const data=await response.json().catch(()=>({}));
      if(!response.ok||!data.ok)throw new Error(data.message||'Não foi possível salvar as taxas.');
      populate(data.configuration);setStatus(data.message||'Taxas atualizadas.');
    }catch(error){setStatus(error instanceof Error?error.message:'Não foi possível salvar as taxas.',true)}
    finally{button.disabled=false}
  });
})();
