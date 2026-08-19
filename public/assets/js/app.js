document.querySelector('[data-sidebar-open]')?.addEventListener('click',()=>{
  document.querySelector('#sidebar')?.classList.add('is-open');
  document.querySelector('.sidebar-backdrop')?.classList.add('is-open');
});
document.querySelectorAll('[data-sidebar-close]').forEach(button=>button.addEventListener('click',()=>{
  document.querySelector('#sidebar')?.classList.remove('is-open');
  document.querySelector('.sidebar-backdrop')?.classList.remove('is-open');
}));
const dashboardSidebar=document.querySelector('#sidebar');
const dashboardSidebarCollapse=document.querySelector('[data-sidebar-collapse]');
if(dashboardSidebar&&localStorage.getItem('tuffer_sidebar_collapsed')==='1'&&innerWidth>760)dashboardSidebar.classList.add('is-collapsed');
const syncDashboardSidebar=()=>{
  const collapsed=dashboardSidebar?.classList.contains('is-collapsed')&&innerWidth>760;
  dashboardSidebar?.querySelectorAll('.sidebar-groups details').forEach(group=>{
    if(collapsed){
      if(group.dataset.sidebarWasOpen===undefined)group.dataset.sidebarWasOpen=group.open?'1':'0';
      group.open=true;
    }else if(group.dataset.sidebarWasOpen!==undefined){
      group.open=group.dataset.sidebarWasOpen==='1';
      delete group.dataset.sidebarWasOpen;
    }
  });
  if(dashboardSidebarCollapse){
    dashboardSidebarCollapse.setAttribute('aria-label',collapsed?'Expandir menu':'Recolher menu');
    dashboardSidebarCollapse.title=collapsed?'Expandir menu':'Recolher menu';
    dashboardSidebarCollapse.setAttribute('aria-expanded',collapsed?'false':'true');
  }
};
dashboardSidebarCollapse?.addEventListener('click',()=>{
  dashboardSidebar?.classList.toggle('is-collapsed');
  localStorage.setItem('tuffer_sidebar_collapsed',dashboardSidebar?.classList.contains('is-collapsed')?'1':'0');
  syncDashboardSidebar();
});
syncDashboardSidebar();

let dashboardSidebarTooltip=null;
const hideDashboardSidebarTooltip=()=>{if(dashboardSidebarTooltip)dashboardSidebarTooltip.hidden=true};
const showDashboardSidebarTooltip=target=>{
  if(!dashboardSidebar?.classList.contains('is-collapsed')||innerWidth<=760)return;
  dashboardSidebarTooltip??=Object.assign(document.createElement('div'),{className:'sidebar-tooltip'});
  if(!dashboardSidebarTooltip.isConnected)document.body.appendChild(dashboardSidebarTooltip);
  dashboardSidebarTooltip.textContent=target.dataset.sidebarTooltip||'';
  dashboardSidebarTooltip.hidden=false;
  const rect=target.getBoundingClientRect();
  dashboardSidebarTooltip.style.left=`${rect.right+10}px`;
  dashboardSidebarTooltip.style.top=`${Math.max(8,rect.top+(rect.height-dashboardSidebarTooltip.offsetHeight)/2)}px`;
};
dashboardSidebar?.addEventListener('mouseover',event=>{const target=event.target.closest?.('[data-sidebar-tooltip]');if(target)showDashboardSidebarTooltip(target)});
dashboardSidebar?.addEventListener('mouseout',event=>{if(event.target.closest?.('[data-sidebar-tooltip]'))hideDashboardSidebarTooltip()});
dashboardSidebar?.addEventListener('focusin',event=>{const target=event.target.closest?.('[data-sidebar-tooltip]');if(target)showDashboardSidebarTooltip(target)});
dashboardSidebar?.addEventListener('focusout',hideDashboardSidebarTooltip);
addEventListener('resize',()=>{hideDashboardSidebarTooltip();syncDashboardSidebar()});

const publicMenu=document.querySelector('[data-public-menu]');
const publicMenuOpen=document.querySelector('[data-public-menu-open]');
let publicMenuReturnFocus=null;
const setPublicMenu=open=>{
  if(!publicMenu||!publicMenuOpen)return;
  publicMenu.classList.toggle('is-open',open);
  publicMenu.setAttribute('aria-hidden',open?'false':'true');
  publicMenuOpen.setAttribute('aria-expanded',open?'true':'false');
  document.body.classList.toggle('has-public-menu',open);
  if(open){publicMenuReturnFocus=document.activeElement;requestAnimationFrame(()=>publicMenu.querySelector('.public-mobile-menu__panel [data-public-menu-close]')?.focus())}
  else if(publicMenuReturnFocus instanceof HTMLElement){publicMenuReturnFocus.focus();publicMenuReturnFocus=null}
};
publicMenuOpen?.addEventListener('click',()=>setPublicMenu(true));
publicMenu?.querySelectorAll('[data-public-menu-close]').forEach(button=>button.addEventListener('click',()=>setPublicMenu(false)));
publicMenu?.querySelectorAll('a').forEach(link=>link.addEventListener('click',()=>setPublicMenu(false)));
document.addEventListener('keydown',event=>{
  if(event.key==='Escape'&&publicMenu?.classList.contains('is-open'))setPublicMenu(false);
  if(event.key!=='Tab'||!publicMenu?.classList.contains('is-open'))return;
  const focusable=[...publicMenu.querySelectorAll('a,button:not([disabled])')].filter(element=>element.tabIndex>=0&&element.getClientRects().length);
  if(!focusable.length)return;
  const first=focusable[0],last=focusable[focusable.length-1];
  if(event.shiftKey&&document.activeElement===first){event.preventDefault();last.focus()}
  else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus()}
});
matchMedia('(min-width:851px)').addEventListener('change',event=>{if(event.matches)setPublicMenu(false)});
setTimeout(()=>document.querySelectorAll('.toast').forEach(toast=>toast.remove()),4500);

const activateProductMedia=(button,stage,buttons)=>{
  if(!button||!stage)return;
  buttons.forEach(item=>item.classList.remove('is-active'));
  button.classList.add('is-active');
  const media=button.dataset.type==='video'?document.createElement('video'):document.createElement('img');
  media.src=button.dataset.src;
  if(media.tagName==='VIDEO'){
    media.controls=true;media.autoplay=true;media.muted=true;media.playsInline=true;media.setAttribute('playsinline','');
  }else media.alt=document.querySelector('.product-buybox h1')?.textContent||'Produto';
  stage.replaceChildren(media);
  if(media.tagName==='VIDEO')media.play().catch(()=>{});
};

const productThumbs=[...document.querySelectorAll('[data-product-thumb]')];
productThumbs.forEach(button=>button.addEventListener('click',()=>activateProductMedia(button,document.querySelector('[data-product-stage]'),productThumbs)));

const productGalleryModal=document.querySelector('[data-product-gallery-modal]');
const productGalleryModalStage=document.querySelector('[data-product-gallery-modal-stage]');
const productGalleryModalThumbs=[...document.querySelectorAll('[data-product-gallery-thumb]')];
const activateGalleryMedia=button=>{
  activateProductMedia(button,productGalleryModalStage,productGalleryModalThumbs);
  const index=productGalleryModalThumbs.indexOf(button);
  const counter=document.querySelector('[data-product-gallery-counter]');
  if(counter&&index>=0)counter.textContent=`${index+1} / ${productGalleryModalThumbs.length}`;
  button?.scrollIntoView({behavior:'smooth',block:'nearest',inline:'center'});
};
const moveProductGallery=direction=>{
  const current=Math.max(0,productGalleryModalThumbs.findIndex(button=>button.classList.contains('is-active')));
  const next=(current+direction+productGalleryModalThumbs.length)%productGalleryModalThumbs.length;
  activateGalleryMedia(productGalleryModalThumbs[next]);
};
const closeProductGallery=()=>{
  if(!productGalleryModal)return;
  productGalleryModalStage?.querySelector('video')?.pause();
  productGalleryModal.hidden=true;
  document.body.classList.remove('has-product-gallery-modal');
};
productGalleryModalThumbs.forEach(button=>button.addEventListener('click',()=>activateGalleryMedia(button)));
document.querySelector('[data-product-gallery-previous]')?.addEventListener('click',()=>moveProductGallery(-1));
document.querySelector('[data-product-gallery-next]')?.addEventListener('click',()=>moveProductGallery(1));
document.querySelector('[data-open-product-gallery]')?.addEventListener('click',()=>{
  if(!productGalleryModal)return;
  productGalleryModal.hidden=false;
  document.body.classList.add('has-product-gallery-modal');
  const activeThumb=document.querySelector('[data-product-thumb].is-active');
  const initial=productGalleryModalThumbs.find(button=>button.dataset.src===activeThumb?.dataset.src)||productGalleryModalThumbs[0];
  activateGalleryMedia(initial);
  productGalleryModal.querySelector('[data-close-product-gallery]')?.focus();
});
document.querySelectorAll('[data-close-product-gallery]').forEach(button=>button.addEventListener('click',closeProductGallery));
productGalleryModal?.addEventListener('click',event=>{if(event.target===productGalleryModal)closeProductGallery()});
document.addEventListener('keydown',event=>{if(!productGalleryModal||productGalleryModal.hidden)return;if(event.key==='Escape')closeProductGallery();if(event.key==='ArrowLeft')moveProductGallery(-1);if(event.key==='ArrowRight')moveProductGallery(1)});

const quickCartModal=document.querySelector('[data-quick-cart-modal]');
const quickCartQuantity=quickCartModal?.querySelector('[data-quick-cart-quantity]');
const closeQuickCart=()=>{
  if(!quickCartModal)return;
  quickCartModal.hidden=true;
  document.body.classList.remove('has-quick-cart-modal');
};
const normalizeQuickCartQuantity=value=>{
  if(!quickCartQuantity)return 1;
  const minimum=Number(quickCartQuantity.min||1);
  const maximum=Number(quickCartQuantity.max||99);
  const normalized=Math.max(minimum,Math.min(maximum,Number(value)||minimum));
  quickCartQuantity.value=String(normalized);
  return normalized;
};
document.querySelectorAll('[data-quick-cart]').forEach(button=>button.addEventListener('click',()=>{
  if(!quickCartModal)return;
  const image=quickCartModal.querySelector('[data-quick-cart-image]');
  const placeholder=quickCartModal.querySelector('[data-quick-cart-placeholder]');
  const storeLogo=quickCartModal.querySelector('[data-quick-cart-store-logo]');
  const productImage=button.dataset.productImage||'';
  const logoUrl=button.dataset.productStoreLogo||'';
  const maximum=Math.max(0,Number(button.dataset.maxQuantity||0));
  quickCartModal.querySelector('[data-quick-cart-title]').textContent=button.dataset.productName||'Produto';
  quickCartModal.querySelector('[data-quick-cart-store]').textContent=button.dataset.productStore||'Loja';
  quickCartModal.querySelector('[data-quick-cart-price]').textContent=`R$ ${button.dataset.productPrice||'0,00'}`;
  quickCartModal.querySelector('[data-quick-cart-link]').href=button.dataset.productUrl||'#';
  quickCartModal.querySelector('[data-quick-cart-variant]').value=button.dataset.variantId||'';
  if(image){image.src=productImage;image.alt=button.dataset.productName||'Produto';image.hidden=!productImage}
  if(placeholder)placeholder.hidden=!!productImage;
  if(storeLogo){storeLogo.replaceChildren();if(logoUrl){const logo=document.createElement('img');logo.src=logoUrl;logo.alt='';storeLogo.append(logo)}else storeLogo.textContent=(button.dataset.productStore||'T').charAt(0)}
  if(quickCartQuantity){quickCartQuantity.max=String(Math.max(1,maximum));quickCartQuantity.value='1';quickCartQuantity.disabled=maximum<1}
  const submit=quickCartModal.querySelector('[data-quick-cart-submit]');
  if(submit){submit.disabled=maximum<1;submit.textContent=maximum<1?'Produto indisponível':'Adicionar ao carrinho'}
  quickCartModal.hidden=false;
  document.body.classList.add('has-quick-cart-modal');
  quickCartModal.querySelector('.quick-cart-modal__dialog [data-close-quick-cart]')?.focus();
}));
quickCartModal?.querySelectorAll('[data-close-quick-cart]').forEach(button=>button.addEventListener('click',closeQuickCart));
quickCartModal?.querySelector('[data-quick-cart-decrease]')?.addEventListener('click',()=>normalizeQuickCartQuantity(Number(quickCartQuantity?.value||1)-1));
quickCartModal?.querySelector('[data-quick-cart-increase]')?.addEventListener('click',()=>normalizeQuickCartQuantity(Number(quickCartQuantity?.value||1)+1));
quickCartQuantity?.addEventListener('change',()=>normalizeQuickCartQuantity(quickCartQuantity.value));
document.addEventListener('keydown',event=>{if(event.key==='Escape'&&quickCartModal&&!quickCartModal.hidden)closeQuickCart()});

document.querySelector('[data-variant-select]')?.addEventListener('change',event=>{
  const option=event.target.selectedOptions[0];
  const stock=Number(option.dataset.stock||0);
  const quantity=document.querySelector('[data-product-quantity]');
  const button=document.querySelector('[data-add-button]');
  const status=document.querySelector('[data-stock-status]');
  if(quantity)quantity.max=String(Math.max(1,stock));
  if(button)button.disabled=stock<1;
  if(status){status.textContent=stock>0?'Em estoque · envio disponível':'Produto temporariamente sem estoque';status.className='stock-status '+(stock>0?'is-available':'is-unavailable')}
});

document.querySelector('[data-shipping-calculator]')?.addEventListener('submit',async event=>{
  event.preventDefault();
  const form=event.currentTarget;
  const calculator=form.closest('.shipping-calculator');
  const result=calculator?.querySelector('[data-shipping-result]');
  const list=calculator?.querySelector('[data-shipping-options]');
  if(!calculator||!result||!list)return;
  if(calculator.dataset.shippingConfigured!=='1'){result.textContent='Cálculo temporariamente indisponível.';return}
  result.textContent='Consultando valores e prazos reais...';list.replaceChildren();
  const body=new FormData(form);
  body.set('variant_id',document.querySelector('[data-variant-select]')?.value||'');
  body.set('quantity',document.querySelector('[data-product-quantity]')?.value||'1');
  try{
    const response=await fetch(calculator.dataset.shippingEndpoint,{method:'POST',body,headers:{'X-Requested-With':'XMLHttpRequest'}});
    const data=await response.json();
    if(!response.ok||!data.ok)throw new Error(data.message||'Não encontramos entrega para este CEP.');
    result.textContent=`${data.options.length} modalidade(s) disponível(is):`;
    data.options.forEach(option=>{
      const row=document.createElement('div');row.className='shipping-quote-option';
      const description=document.createElement('span');
      const service=document.createElement('strong');service.textContent=String(option.service||'Entrega');
      const deadline=document.createElement('small');deadline.textContent=`${option.carrier||'Transportadora'} · entre ${option.arrival_min} e ${option.arrival_max}`;
      const price=document.createElement('b');price.textContent=Number(option.price||0).toLocaleString('pt-BR',{style:'currency',currency:'BRL'});
      description.append(service,deadline);row.append(description,price);list.append(row);
    });
  }catch(error){result.textContent=error instanceof Error?error.message:'Não foi possível calcular o frete agora.'}
});

document.querySelectorAll('[data-toggle-password]').forEach(button=>{
  button.addEventListener('click',()=>{
    const field=document.getElementById(button.dataset.togglePassword);
    if(!field)return;
    const shouldShow=field.type==='password';
    field.type=shouldShow?'text':'password';
    if(button.hasAttribute('data-password-icon'))button.classList.toggle('is-visible',shouldShow);
    else button.textContent=shouldShow?'Ocultar':'Mostrar';
    button.setAttribute('aria-label',shouldShow?'Ocultar senha':'Mostrar senha');
    button.setAttribute('aria-pressed',String(shouldShow));
  });
});

document.querySelector('[data-auth-login-form]')?.addEventListener('submit',event=>{
  const form=event.currentTarget;
  if(!form.checkValidity())return;
  const button=form.querySelector('[data-auth-login-submit]');
  if(button){button.disabled=true;button.classList.add('is-loading');button.setAttribute('aria-label','Entrando na sua conta')}
});

document.querySelectorAll('[data-code-form]').forEach(form=>{
  const fields=[...form.querySelectorAll('[data-code-inputs] input')];
  const hiddenField=form.querySelector('[data-complete-code]');
  const updateCode=()=>{
    hiddenField.value=fields.map(field=>field.value.replace(/\D/g,'')).join('');
  };

  fields.forEach((field,index)=>{
    field.addEventListener('input',()=>{
      field.value=field.value.replace(/\D/g,'').slice(0,1);
      if(field.value&&fields[index+1])fields[index+1].focus();
      updateCode();
    });
    field.addEventListener('keydown',event=>{
      if(event.key==='Backspace'&&!field.value&&fields[index-1])fields[index-1].focus();
      if(event.key==='ArrowLeft'&&fields[index-1])fields[index-1].focus();
      if(event.key==='ArrowRight'&&fields[index+1])fields[index+1].focus();
    });
    field.addEventListener('paste',event=>{
      event.preventDefault();
      const code=event.clipboardData.getData('text').replace(/\D/g,'').slice(0,8);
      code.split('').forEach((number,position)=>{if(fields[position])fields[position].value=number});
      if(code.length)fields[Math.min(code.length,8)-1]?.focus();
      updateCode();
    });
  });
  form.addEventListener('submit',updateCode);
});

document.querySelectorAll('[data-stepped-form]').forEach(form=>{
  const card=form.closest('[data-stepped-card]');
  const steps=[...form.querySelectorAll('[data-auth-step]')];
  const indicators=[...(card?.querySelectorAll('[data-step-indicator]')||[])];
  let current=Math.min(2,Math.max(1,Number(form.dataset.initialStep)||1));

  const showStep=(step,shouldScroll=true)=>{
    current=step;
    steps.forEach(panel=>panel.hidden=Number(panel.dataset.authStep)!==step);
    indicators.forEach(indicator=>indicator.classList.toggle('is-active',Number(indicator.dataset.stepIndicator)<=step));
    if(shouldScroll)card?.scrollIntoView({behavior:'smooth',block:'start'});
  };

  form.querySelector('[data-auth-next]')?.addEventListener('click',()=>{
    const active=steps.find(panel=>Number(panel.dataset.authStep)===current);
    const fields=[...(active?.querySelectorAll('input,select,textarea')||[])];
    const invalid=fields.find(field=>!field.checkValidity());
    if(invalid){invalid.reportValidity();return}
    showStep(2);
    steps.find(panel=>Number(panel.dataset.authStep)===2)?.querySelector('input')?.focus();
  });
  form.querySelector('[data-auth-previous]')?.addEventListener('click',()=>showStep(1));
  showStep(current,false);
});

document.querySelectorAll('[data-product-editor]').forEach(editor=>{
  const q=(selector,root=editor)=>root.querySelector(selector);
  const qa=(selector,root=editor)=>[...root.querySelectorAll(selector)];
  const parse=value=>{try{return JSON.parse(value||'[]')}catch{return[]}};
  const escape=value=>String(value??'').replace(/[&<>'"]/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[char]));
  const money=value=>Number(value||0).toLocaleString('pt-BR',{style:'currency',currency:'BRL'});
  const slugify=value=>String(value).normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'');
  const panels=qa('[data-product-step]');
  const stepButtons=qa('[data-product-step-button]');
  let currentStep=1;
  let variants=parse(q('[data-variants-json]')?.value);
  let wholesaleRows=parse(q('[data-wholesale-json]')?.value);
  let specificationRows=parse(q('[data-specifications-json]')?.value);
  let shippingRows=parse(q('[data-shipping-json]')?.value);
  let mediaQueue=[];
  let uploadedPayload=[];
  let readyToSubmit=false;

  const showStep=step=>{
    currentStep=Math.max(1,Math.min(7,step));
    panels.forEach(panel=>{panel.hidden=Number(panel.dataset.productStep)!==currentStep;panel.classList.toggle('is-active',!panel.hidden)});
    stepButtons.forEach(button=>button.classList.toggle('is-active',Number(button.dataset.productStepButton)===currentStep));
    editor.scrollIntoView({behavior:'smooth',block:'start'});
    updateReview();
  };
  stepButtons.forEach(button=>button.addEventListener('click',()=>showStep(Number(button.dataset.productStepButton))));
  qa('[data-next-product-step]').forEach(button=>button.addEventListener('click',()=>showStep(currentStep+1)));
  qa('[data-previous-product-step]').forEach(button=>button.addEventListener('click',()=>showStep(currentStep-1)));

  const nameField=q('[data-product-name]');
  const summaryField=q('[data-product-summary]');
  const skuField=q('[data-product-sku]');
  const seoTitleField=q('[name="seo_title"]');
  const seoDescriptionField=q('[name="seo_description"]');
  const seoKeywordsField=q('[name="seo_keywords"]');
  const richContent=q('[data-rich-text-content]');
  const richInput=q('[data-rich-text-input]');
  const autoSkuSuffix=Math.random().toString(36).slice(2,8).toUpperCase().padEnd(6,'0');
  let skuCustomized=Boolean(skuField?.value.trim());
  const generatedSku=()=>{const base=slugify(nameField?.value||'produto').split('-').filter(Boolean).slice(0,4).join('-').toUpperCase()||'PRODUTO';return `TUF-${base}-${autoSkuSuffix}`.slice(0,100)};
  const generatedKeywords=()=>{const ignored=new Set(['a','as','com','da','das','de','do','dos','e','em','o','os','para','por','um','uma']);return [...new Set(slugify(nameField?.value||'').split('-').filter(token=>token.length>=2&&!ignored.has(token)))].slice(0,10).join(', ')};
  const syncAutoFields=()=>{
    if(skuField&&!skuCustomized)skuField.value=generatedSku();
    if(seoTitleField?.dataset.seoAuto==='true')seoTitleField.value=(nameField?.value||'').slice(0,190);
    if(seoDescriptionField?.dataset.seoAuto==='true')seoDescriptionField.value=(summaryField?.value||'').slice(0,320);
    if(seoKeywordsField?.dataset.seoAuto==='true')seoKeywordsField.value=generatedKeywords();
  };
  skuField?.addEventListener('input',()=>{skuCustomized=skuField.value.trim()!=='';if(!skuCustomized)syncAutoFields()});
  [seoTitleField,seoDescriptionField,seoKeywordsField].forEach(field=>field?.addEventListener('input',()=>{field.dataset.seoAuto='false'}));
  nameField?.addEventListener('input',()=>{
    q('[data-name-count]').textContent=String(nameField.value.length);
    const slug=q('[name="slug"]');
    const preview=q('[data-slug-preview]');
    if(preview){const base=preview.textContent.replace(/\/produto\/.*/, '/produto/');preview.textContent=base+slugify(slug?.value||nameField.value||'nome-do-produto')}
    syncAutoFields();
  });
  summaryField?.addEventListener('input',syncAutoFields);
  q('[name="slug"]')?.addEventListener('input',()=>nameField?.dispatchEvent(new Event('input')));

  const syncRichText=()=>{if(richInput&&richContent)richInput.value=richContent.innerHTML.trim()};
  richContent?.addEventListener('input',syncRichText);
  qa('[data-rich-command]').forEach(button=>button.addEventListener('click',()=>{richContent?.focus();document.execCommand(button.dataset.richCommand,false);syncRichText();updateQuality()}));
  qa('[data-rich-block]').forEach(button=>button.addEventListener('click',()=>{richContent?.focus();document.execCommand('formatBlock',false,button.dataset.richBlock);syncRichText();updateQuality()}));
  q('[data-rich-link]')?.addEventListener('click',()=>{const href=prompt('Informe o endereço do link:','https://');if(!href)return;richContent?.focus();document.execCommand('createLink',false,href);syncRichText();updateQuality()});

  const updateModeVisibility=()=>{
    const wholesale=q('[data-sale-mode="wholesale"]')?.checked;
    const retail=q('[data-sale-mode="retail"]')?.checked;
    q('[data-wholesale-fields]')?.toggleAttribute('hidden',!wholesale);
    q('[data-retail-fields]')?.toggleAttribute('hidden',!retail);
    const retailPrice=q('[data-retail-price]');if(retailPrice)retailPrice.required=!!retail;
    const variable=q('[name="product_type"]:checked')?.value==='variable';
    q('[data-variation-builder]')?.toggleAttribute('hidden',!variable);
    q('[data-simple-stock]')?.toggleAttribute('hidden',variable);
    const separated=q('[name="stock_control"]:checked')?.value==='separate';
    q('[data-separated-stock]')?.toggleAttribute('hidden',!separated);
  };
  qa('[data-sale-mode],[name="product_type"],[name="stock_control"]').forEach(field=>field.addEventListener('change',updateModeVisibility));

  const updatePrices=()=>{
    const price=Number(q('[data-retail-price]')?.value||0);
    const promotional=Number(q('[data-promo-price]')?.value||0);
    const cost=Number(q('[data-cost-price]')?.value||0);
    const sale=promotional>0?promotional:price;
    q('[data-margin]').textContent=sale>0?money(sale-cost):'—';
    q('[data-discount]').textContent=promotional>0&&price>0?`${Math.round((1-promotional/price)*100)}%`:'—';
    updateQuality();updateReview();
  };
  qa('[data-retail-price],[data-promo-price],[data-cost-price]').forEach(field=>field.addEventListener('input',updatePrices));

  const renderWholesale=()=>{
    const root=q('[data-wholesale-rows]');if(!root)return;
    root.innerHTML=wholesaleRows.map((row,index)=>`<div class="dynamic-row" data-row="${index}"><label>Quantidade mínima<input type="number" min="1" value="${escape(row.minimum_quantity)}" data-key="minimum_quantity"></label><label>Quantidade máxima<input type="number" min="1" value="${escape(row.maximum_quantity??'')}" data-key="maximum_quantity" placeholder="Sem limite"></label><label>Preço por unidade<input type="number" min="0.01" step="0.01" value="${escape(row.unit_price)}" data-key="unit_price"></label><button type="button" data-remove-row aria-label="Remover faixa">×</button></div>`).join('');
    bindDynamic(root,wholesaleRows,renderWholesale,q('[data-wholesale-json]'));
  };
  const renderSpecifications=()=>{
    const root=q('[data-specification-rows]');if(!root)return;
    root.innerHTML=specificationRows.map((row,index)=>`<div class="dynamic-row dynamic-row--spec" data-row="${index}"><label>Especificação<input value="${escape(row.name)}" data-key="name" placeholder="Material"></label><label>Valor<input value="${escape(row.value)}" data-key="value" placeholder="Microfibra"></label><button type="button" data-remove-row aria-label="Remover">×</button></div>`).join('');
    bindDynamic(root,specificationRows,renderSpecifications,q('[data-specifications-json]'),true);
  };
  const renderShipping=()=>{
    const root=q('[data-shipping-rows]');if(!root)return;
    root.innerHTML=shippingRows.map((row,index)=>`<div class="dynamic-row dynamic-row--shipping" data-row="${index}">${[['minimum_quantity','Qtd. mín.'],['maximum_quantity','Qtd. máx.'],['weight','Peso kg'],['width','Largura'],['height','Altura'],['length','Comprimento']].map(([key,label])=>`<label>${label}<input type="number" min="0" step="0.01" value="${escape(row[key]??'')}" data-key="${key}"></label>`).join('')}<button type="button" data-remove-row aria-label="Remover">×</button></div>`).join('');
    bindDynamic(root,shippingRows,renderShipping,q('[data-shipping-json]'));
  };
  function bindDynamic(root,rows,render,hidden,withOrder=false){
    qa('[data-row]',root).forEach(row=>qa('input',row).forEach(input=>input.addEventListener('input',()=>{const index=Number(row.dataset.row);rows[index][input.dataset.key]=input.value;if(withOrder)rows[index].sort_order=index;hidden.value=JSON.stringify(rows);updateQuality()})));
    qa('[data-remove-row]',root).forEach(button=>button.addEventListener('click',()=>{rows.splice(Number(button.closest('[data-row]').dataset.row),1);hidden.value=JSON.stringify(rows);render();updateQuality()}));
    hidden.value=JSON.stringify(rows);
  }
  q('[data-add-wholesale]')?.addEventListener('click',()=>{wholesaleRows.push({minimum_quantity:'',maximum_quantity:'',unit_price:''});renderWholesale()});
  q('[data-add-specification]')?.addEventListener('click',()=>{specificationRows.push({name:'',value:'',sort_order:specificationRows.length});renderSpecifications()});
  q('[data-add-shipping]')?.addEventListener('click',()=>{shippingRows.push({minimum_quantity:'',maximum_quantity:'',weight:'',width:'',height:'',length:''});renderShipping()});

  const renderVariants=()=>{
    const root=q('[data-variant-rows]');if(!root)return;
    root.innerHTML=variants.map((row,index)=>`<tr data-variant-row="${index}"><td><input value="${escape(row.name)}" data-key="name"></td><td><input value="${escape(row.sku)}" data-key="sku"></td><td><input type="number" min="0.01" step="0.01" value="${escape(row.price)}" data-key="price"></td><td><input type="number" min="0" step="0.01" value="${escape(row.wholesale_price??'')}" data-key="wholesale_price"></td><td><input type="number" min="0" value="${escape(row.stock??0)}" data-key="stock"></td><td><select data-key="status"><option value="active" ${row.status!=='inactive'?'selected':''}>Ativo</option><option value="inactive" ${row.status==='inactive'?'selected':''}>Inativo</option></select></td><td><button type="button" data-remove-variant aria-label="Remover">×</button></td></tr>`).join('');
    qa('[data-variant-row]',root).forEach(row=>qa('input,select',row).forEach(input=>input.addEventListener('input',()=>{variants[Number(row.dataset.variantRow)][input.dataset.key]=input.value;q('[data-variants-json]').value=JSON.stringify(variants);updateReview()})));
    qa('[data-remove-variant]',root).forEach(button=>button.addEventListener('click',()=>{variants.splice(Number(button.closest('[data-variant-row]').dataset.variantRow),1);q('[data-variants-json]').value=JSON.stringify(variants);renderVariants()}));
    q('[data-variants-json]').value=JSON.stringify(variants);
  };
  q('[data-generate-variants]')?.addEventListener('click',()=>{
    const sizes=(q('[data-attribute-sizes]')?.value||'').split(',').map(v=>v.trim()).filter(Boolean);
    const colors=(q('[data-attribute-colors]')?.value||'').split(',').map(v=>v.trim()).filter(Boolean);
    const groups=[sizes,colors].filter(group=>group.length);
    if(!groups.length){alert('Informe ao menos um tamanho ou uma cor.');return}
    const combinations=groups.reduce((acc,group)=>acc.flatMap(prefix=>group.map(value=>[...prefix,value])),[[]]);
    const baseSku=q('[name="sku"]')?.value||'SKU';
    const basePrice=q('[name="price"]')?.value||'';
    variants=combinations.map((parts,index)=>({name:parts.join(' / '),sku:`${baseSku}-${parts.map(part=>slugify(part).slice(0,3).toUpperCase()).join('-')}`,price:basePrice,wholesale_price:q('[name="wholesale_price"]')?.value||'',stock:0,minimum_quantity:0,status:'active',...((variants[index]?.id)?{id:variants[index].id}:{})}));
    renderVariants();
  });

  const gallery=q('[data-media-gallery]');
  const imageInput=q('[data-image-input]');
  const videoInput=q('[data-video-input]');
  const mediaOrderInput=q('[data-media-order]');
  const mediaCoverInput=q('[data-media-cover]');
  let draggedMediaCard=null;
  const activeMediaCards=()=>qa('[data-media-key]',gallery).filter(card=>!card.hidden);
  const existingImages=()=>qa('[data-existing-media]',gallery).filter(card=>!card.hidden&&!q('[data-delete-media]',card)?.checked).length;
  const syncMediaState=()=>{
    const cards=activeMediaCards();
    let cover=mediaCoverInput?.value||'';
    if(!cards.some(card=>card.dataset.mediaKey===cover))cover=cards[0]?.dataset.mediaKey||'';
    if(mediaCoverInput)mediaCoverInput.value=cover;
    if(mediaOrderInput)mediaOrderInput.value=JSON.stringify(cards.map(card=>card.dataset.mediaKey));
    cards.forEach(card=>{
      const isCover=card.dataset.mediaKey===cover;
      card.classList.toggle('is-cover',isCover);
      const badge=q('[data-cover-badge]',card);if(badge)badge.hidden=!isCover;
      const button=q('[data-set-media-cover]',card);if(button){button.setAttribute('aria-pressed',String(isCover));button.textContent=isCover?'Capa definida':'Definir capa'}
    });
  };
  const bindMediaCard=card=>{
    q('[data-set-media-cover]',card)?.addEventListener('click',()=>{if(mediaCoverInput)mediaCoverInput.value=card.dataset.mediaKey;syncMediaState()});
    q('[data-delete-existing-media]',card)?.addEventListener('click',()=>{
      const input=q('[data-delete-media]',card);if(input)input.checked=true;
      card.hidden=true;syncMediaState();updateQuality();updateReview();
    });
    card.addEventListener('dragstart',event=>{draggedMediaCard=card;card.classList.add('is-dragging');event.dataTransfer.effectAllowed='move'});
    card.addEventListener('dragend',()=>{card.classList.remove('is-dragging');draggedMediaCard=null;syncMediaState()});
  };
  gallery?.addEventListener('dragover',event=>{
    if(!draggedMediaCard)return;
    event.preventDefault();
    const target=event.target.closest('[data-media-key]');
    if(!target||target===draggedMediaCard||target.hidden)return;
    const after=event.clientX>target.getBoundingClientRect().left+target.offsetWidth/2;
    gallery.insertBefore(draggedMediaCard,after?target.nextSibling:target);
  });
  gallery?.addEventListener('drop',event=>{if(draggedMediaCard){event.preventDefault();syncMediaState()}});
  const cropImage=file=>new Promise((resolve,reject)=>{const image=new Image();const url=URL.createObjectURL(file);image.onload=()=>{const canvas=document.createElement('canvas');canvas.width=1080;canvas.height=1080;const context=canvas.getContext('2d');const scale=Math.min(1080/image.naturalWidth,1080/image.naturalHeight);const width=Math.round(image.naturalWidth*scale);const height=Math.round(image.naturalHeight*scale);context.imageSmoothingEnabled=true;context.imageSmoothingQuality='high';context.fillStyle='#fff';context.fillRect(0,0,1080,1080);context.drawImage(image,Math.round((1080-width)/2),Math.round((1080-height)/2),width,height);canvas.toBlob(blob=>{URL.revokeObjectURL(url);if(!blob){reject(new Error('Não foi possível preparar a imagem.'));return}resolve(new File([blob],file.name.replace(/\.[^.]+$/,'.jpg'),{type:'image/jpeg'}))},'image/jpeg',.9)};image.onerror=()=>{URL.revokeObjectURL(url);reject(new Error('Não foi possível ler a imagem.'))};image.src=url});
  const addImages=async files=>{
    const allowed=['image/jpeg','image/png','image/webp'];
    const slots=10-existingImages()-mediaQueue.filter(item=>item.type==='image').length;
    if(slots<=0){alert('Você já adicionou o limite de 10 imagens.');return}
    for(const file of [...files].slice(0,slots)){
      if(!allowed.includes(file.type)){alert(`${file.name}: formato inválido.`);continue}
      try{
        const prepared=await cropImage(file);const clientKey=`upload-${Date.now()}-${Math.random().toString(36).slice(2,9)}`;const item={type:'image',file:prepared,status:'ready',clientKey};mediaQueue.push(item);
        const card=document.createElement('article');card.className='media-card';card.draggable=true;card.dataset.mediaKey=`new:${clientKey}`;card.innerHTML=`<button class="media-card__drag" type="button" title="Arraste para reordenar" aria-label="Arraste para reordenar">⠿</button><img src="${URL.createObjectURL(prepared)}" alt="Prévia"><span class="media-card__cover" data-cover-badge hidden>Capa</span><small data-media-status>Pronta · 1080 × 1080</small><div class="media-card__actions"><button type="button" data-set-media-cover>Definir capa</button><button type="button" class="is-danger" data-remove-queued>Excluir</button></div>`;
        item.card=card;gallery.append(card);bindMediaCard(card);q('[data-remove-queued]',card).addEventListener('click',()=>{mediaQueue=mediaQueue.filter(entry=>entry!==item);card.remove();syncMediaState();updateQuality();updateReview()});syncMediaState();
      }catch(error){alert(error.message)}
    }updateQuality();updateReview();
  };
  q('[data-image-dropzone]')?.addEventListener('click',()=>imageInput?.click());
  q('[data-image-dropzone]')?.addEventListener('keydown',event=>{if(event.key==='Enter'||event.key===' '){event.preventDefault();imageInput?.click()}});
  imageInput?.addEventListener('change',()=>addImages(imageInput.files));
  const dropzone=q('[data-image-dropzone]');
  ['dragenter','dragover'].forEach(type=>dropzone?.addEventListener(type,event=>{event.preventDefault();dropzone.classList.add('is-dragging')}));
  ['dragleave','drop'].forEach(type=>dropzone?.addEventListener(type,event=>{event.preventDefault();dropzone.classList.remove('is-dragging');if(type==='drop')addImages([...event.dataTransfer.files].filter(file=>file.type.startsWith('image/')))}));
  dropzone?.addEventListener('paste',event=>{const files=[...(event.clipboardData?.files||[])].filter(file=>file.type.startsWith('image/'));if(files.length){event.preventDefault();addImages(files)}});
  q('[data-video-button]')?.addEventListener('click',()=>videoInput?.click());
  videoInput?.addEventListener('change',()=>{
    const file=videoInput.files?.[0];
    if(!file)return;
    const extension=file.name.split('.').pop()?.toLowerCase();
    const allowedTypes=['video/mp4','video/webm','video/quicktime','video/x-m4v'];
    if(!allowedTypes.includes(file.type)&&!['mp4','webm','mov','m4v'].includes(extension)){
      alert('Use um vídeo MP4, WebM, MOV ou M4V.');
      videoInput.value='';
      return;
    }
    if(file.size>100*1024*1024){
      alert('O vídeo deve ter no máximo 100 MB.');
      videoInput.value='';
      return;
    }
    if(mediaQueue.some(item=>item.type==='video')||q('[data-video-preview] video')){
      alert('Este produto já possui um vídeo. Exclua ou substitua o atual.');
      videoInput.value='';
      return;
    }

    const preview=q('[data-video-preview]');
    const video=document.createElement('video');
    const url=URL.createObjectURL(file);
    let metadataHandled=false;
    const queueVideo=(duration=null)=>{
      if(metadataHandled)return;
      metadataHandled=true;
      const item={type:'video',file,status:'ready',localDuration:duration};
      mediaQueue.push(item);
      if(Number.isFinite(duration)){
        preview.innerHTML=`<video src="${url}" muted controls></video><div><strong>${escape(file.name)}</strong><small>${Math.ceil(duration)} segundos · pronto para enviar</small><button type="button" class="text-button is-danger" data-remove-queued-video>Remover</button></div>`;
      }else{
        URL.revokeObjectURL(url);
        preview.innerHTML=`<div><strong>${escape(file.name)}</strong><small>Pronto para enviar · duração será validada no processamento</small><button type="button" class="text-button is-danger" data-remove-queued-video>Remover</button></div>`;
      }
      q('[data-remove-queued-video]',preview)?.addEventListener('click',()=>{
        mediaQueue=mediaQueue.filter(entry=>entry!==item);
        if(Number.isFinite(duration))URL.revokeObjectURL(url);
        preview.innerHTML='';
        videoInput.value='';
        updateQuality();
        updateReview();
      });
      updateQuality();
    };

    video.preload='metadata';
    video.onloadedmetadata=()=>{
      const duration=Number(video.duration);
      if(!Number.isFinite(duration)||duration<8||duration>80){
        metadataHandled=true;
        URL.revokeObjectURL(url);
        videoInput.value='';
        alert('O vídeo deve ter entre 8 segundos e 1 minuto e 20 segundos.');
        return;
      }
      queueVideo(duration);
    };
    // Alguns navegadores não leem metadados de MOV/HEVC, embora o Cloudinary
    // consiga inspecionar e converter o arquivo. A validação definitiva ocorre
    // com a duração devolvida pelo Cloudinary depois do upload.
    video.onerror=()=>queueVideo();
    video.src=url;
  });

  const uploadQueuedMedia=async()=>{
    if(!mediaQueue.length)return;
    const cloud=editor.dataset.cloudName;const preset=editor.dataset.uploadPreset;const folder=editor.dataset.cloudFolder||'tuffer/products';
    if(!cloud||!preset)throw new Error('Configure o Cloudinary antes de enviar novas mídias.');
    for(const item of mediaQueue.filter(entry=>entry.status!=='uploaded')){
      item.status='uploading';if(item.card){item.card.classList.add('is-processing');q('[data-media-status]',item.card).textContent='Enviando...'}
      const body=new FormData();body.append('file',item.file);body.append('upload_preset',preset);body.append('folder',folder);
      const response=await fetch(`https://api.cloudinary.com/v1_1/${encodeURIComponent(cloud)}/${item.type}/upload`,{method:'POST',body});
      const result=await response.json();if(!response.ok)throw new Error(result?.error?.message||`Falha ao enviar ${item.file.name}.`);
      if(item.type==='video'){
        const duration=Number(result.duration);
        if(result.resource_type!=='video')throw new Error('O arquivo enviado não foi reconhecido como vídeo.');
        if(!Number.isFinite(duration)||duration<=0)throw new Error('Não foi possível identificar a duração do vídeo após o envio. Converta-o para MP4 com vídeo H.264 e áudio AAC.');
        if(duration<8||duration>80)throw new Error('O vídeo deve ter entre 8 segundos e 1 minuto e 20 segundos.');
        if(Number(result.bytes)>100*1024*1024)throw new Error('O vídeo deve ter no máximo 100 MB.');
        result.duration=duration;
        // Entrega uma versão compatível com os navegadores, inclusive quando a
        // origem é MOV/HEVC. O arquivo original continua preservado no Cloudinary.
        result.secure_url=result.secure_url
          .replace('/video/upload/','/video/upload/f_mp4,vc_h264,ac_aac,q_auto/')
          .replace(/\.[a-z0-9]+(?=$|[?#])/i,'.mp4');
        result.url=result.secure_url;
        result.format='mp4';
      }
      const uploaded={resource_type:result.resource_type,public_id:result.public_id,url:result.url,secure_url:result.secure_url,thumbnail_url:result.secure_url,format:result.format,width:result.width,height:result.height,bytes:result.bytes,duration:result.duration};if(item.clientKey)uploaded.client_key=item.clientKey;uploadedPayload.push(uploaded);item.status='uploaded';if(item.card){item.card.classList.remove('is-processing');q('[data-media-status]',item.card).textContent='Enviada'}
    }
    q('[data-media-payload]').value=JSON.stringify(uploadedPayload);
  };

  const categoryData=parse(q('[data-category-data]')?.textContent).map(category=>({...category,id:Number(category.id),parent_id:category.parent_id===null?null:Number(category.parent_id),depth:Number(category.depth||0),allow_products:Number(category.allow_products??1)===1}));
  const categoryById=new Map(categoryData.map(category=>[category.id,category]));
  const categoryChildren=new Map();
  categoryData.forEach(category=>{const key=category.parent_id??0;if(!categoryChildren.has(key))categoryChildren.set(key,[]);categoryChildren.get(key).push(category)});
  const isCategoryLeaf=category=>category.allow_products&&!(categoryChildren.get(category.id)?.length);
  const normalizeText=value=>String(value||'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();
  const categoryModal=q('[data-category-modal]');
  const categorySearch=q('[data-category-picker-search]');
  const categoryBrowser=q('[data-category-browser]');
  const categoryBreadcrumb=q('[data-category-breadcrumb]');
  const categoryPreview=q('[data-category-preview]');
  const confirmCategory=q('[data-confirm-category]');
  const additionalRoot=q('[data-additional-category-list]');
  let additionalCategoryIds=qa('[data-additional-category]').map(chip=>Number(chip.dataset.additionalCategory));
  let categoryMode='primary';
  let currentCategoryParent=null;
  let pendingCategoryId=null;

  const primaryCategoryId=()=>Number(q('[data-primary-category-id]')?.value||0);
  const categoryAncestors=category=>{
    const result=[];let current=category;
    while(current){result.unshift(current);current=current.parent_id?categoryById.get(current.parent_id):null}
    return result;
  };
  const renderAdditionalCategories=()=>{
    if(!additionalRoot)return;
    additionalCategoryIds=additionalCategoryIds.filter((id,index,array)=>categoryById.has(id)&&id!==primaryCategoryId()&&array.indexOf(id)===index).slice(0,3);
    additionalRoot.innerHTML=additionalCategoryIds.map(id=>{const category=categoryById.get(id);return `<span class="additional-category-chip" data-additional-category="${id}">${escape(category.path)}<button type="button" data-remove-additional-category="${id}" aria-label="Remover categoria">×</button><input type="hidden" name="categories[]" value="${id}"></span>`}).join('')+(additionalCategoryIds.length<3?'<button class="add-category-button" type="button" data-open-category-picker="additional">+ Adicionar categoria</button>':'');
    qa('[data-remove-additional-category]',additionalRoot).forEach(button=>button.addEventListener('click',()=>{additionalCategoryIds=additionalCategoryIds.filter(id=>id!==Number(button.dataset.removeAdditionalCategory));renderAdditionalCategories()}));
    q('[data-open-category-picker="additional"]',additionalRoot)?.addEventListener('click',()=>openCategoryPicker('additional'));
  };
  const choosePendingCategory=id=>{
    pendingCategoryId=id;
    const category=categoryById.get(id);
    if(categoryPreview)categoryPreview.innerHTML=category?`<small>Categoria selecionada</small><strong>${escape(category.path)}</strong>`:'Nenhuma categoria selecionada';
    if(confirmCategory)confirmCategory.disabled=!category;
    qa('[data-category-option]',categoryBrowser).forEach(option=>option.classList.toggle('is-selected',Number(option.dataset.categoryOption)===id));
  };
  const renderCategoryBreadcrumb=()=>{
    if(!categoryBreadcrumb)return;
    const trail=currentCategoryParent?categoryAncestors(categoryById.get(currentCategoryParent)):[];
    categoryBreadcrumb.innerHTML=`<button type="button" data-category-parent="0">Todas as categorias</button>${trail.map(category=>`<span>›</span><button type="button" data-category-parent="${category.id}">${escape(category.name)}</button>`).join('')}`;
    qa('[data-category-parent]',categoryBreadcrumb).forEach(button=>button.addEventListener('click',()=>{currentCategoryParent=Number(button.dataset.categoryParent)||null;pendingCategoryId=null;if(categorySearch)categorySearch.value='';renderCategoryPicker()}));
  };
  const categorySearchMatches=term=>{
    const normalized=normalizeText(term).trim();if(!normalized)return [];
    const synonymRules=[['cueca box','boxer'],['cueca boxe','boxer'],['calcinha alta','cintura alta'],['fio','fio dental'],['samba cancao','samba-canção']];
    const expanded=[normalized,...synonymRules.filter(([source])=>normalized.includes(source)).map(([,target])=>normalizeText(target))];
    return categoryData.filter(isCategoryLeaf).filter(category=>expanded.some(search=>normalizeText(category.path).includes(search))).slice(0,30);
  };
  const renderCategorySuggestions=()=>{
    const root=q('[data-category-suggestions]');if(!root)return;
    const productName=normalizeText(nameField?.value);
    const tokens=productName.split(/[^a-z0-9]+/).filter(token=>token.length>=4&&!['produto','adulto','feminina','feminino','cores'].includes(token));
    const ranked=categoryData.filter(isCategoryLeaf).map(category=>{const path=normalizeText(category.path);let score=tokens.reduce((total,token)=>total+(path.includes(token)?1:0),0);if(productName.includes('kit')&&path.includes('kits'))score+=2;if(productName.includes('boxer')&&path.includes('boxer'))score+=3;if(productName.includes('tanga')&&path.includes('tanga'))score+=3;if(productName.includes('plus size')&&path.includes('plus size'))score+=2;return{category,score}}).filter(item=>item.score>0).sort((a,b)=>b.score-a.score||a.category.path.localeCompare(b.category.path)).slice(0,3);
    root.hidden=!ranked.length;
    const content=root.querySelector('div');if(content)content.innerHTML=ranked.map(({category,score})=>`<button type="button" data-suggested-category="${category.id}"><span><strong>${escape(category.path)}</strong><small>${score>=3?'Confiança alta':'Sugestão relacionada'}</small></span><b>Usar sugestão</b></button>`).join('');
    qa('[data-suggested-category]',root).forEach(button=>button.addEventListener('click',()=>{pendingCategoryId=Number(button.dataset.suggestedCategory);currentCategoryParent=categoryById.get(pendingCategoryId)?.parent_id||null;if(categorySearch)categorySearch.value='';renderCategoryPicker();choosePendingCategory(pendingCategoryId)}));
  };
  function renderCategoryPicker(){
    if(!categoryBrowser)return;
    const term=categorySearch?.value||'';
    const options=term.trim()?categorySearchMatches(term):(categoryChildren.get(currentCategoryParent??0)||[]);
    categoryBrowser.innerHTML=options.map(category=>{const children=categoryChildren.get(category.id)?.length||0;const leaf=isCategoryLeaf(category);const blocked=children===0&&!category.allow_products;return `<button type="button" class="category-option ${leaf?'category-option--leaf':''}" data-category-option="${category.id}" data-has-children="${children>0}" ${blocked?'disabled':''}><span><strong>${escape(category.name)}</strong><small>${blocked?'Não recebe produtos':(term.trim()?escape(category.path):(leaf?escape(category.path.split(' › ').slice(0,-1).join(' › ')):`${children} subcategoria(s)`) )}</small></span><span class="${leaf?'category-option__select':''}">${leaf?'Selecionar':(blocked?'Bloqueada':'›')}</span></button>`}).join('')||'<div class="category-browser__empty"><strong>Nenhuma categoria encontrada</strong><p>Tente outro termo de busca.</p></div>';
    qa('[data-category-option]',categoryBrowser).forEach(button=>button.addEventListener('click',()=>{const id=Number(button.dataset.categoryOption);if(button.dataset.hasChildren==='true'){currentCategoryParent=id;pendingCategoryId=null;if(categorySearch)categorySearch.value='';renderCategoryPicker();return}choosePendingCategory(id)}));
    renderCategoryBreadcrumb();
    if(pendingCategoryId)choosePendingCategory(pendingCategoryId);
  }
  function openCategoryPicker(mode,parentHint=0){
    if(!categoryModal)return;
    const hintedParent=Number(parentHint||0);
    categoryMode=mode;currentCategoryParent=mode==='primary'&&categoryById.has(hintedParent)?hintedParent:null;pendingCategoryId=null;
    if(categorySearch)categorySearch.value='';
    if(categoryPreview)categoryPreview.textContent='Nenhuma categoria selecionada';
    if(confirmCategory)confirmCategory.disabled=true;
    q('#category-modal-title').textContent=mode==='primary'?'Escolha a categoria principal':'Adicionar categoria';
    categoryModal.hidden=false;document.body.classList.add('has-category-modal');
    renderCategorySuggestions();renderCategoryPicker();setTimeout(()=>categorySearch?.focus(),0);
  }
  const closeCategoryPicker=()=>{if(!categoryModal)return;categoryModal.hidden=true;document.body.classList.remove('has-category-modal')};
  qa('[data-open-category-picker]').forEach(button=>button.addEventListener('click',()=>openCategoryPicker(button.dataset.openCategoryPicker,button.dataset.categoryParentHint)));
  qa('[data-close-category-modal]').forEach(button=>button.addEventListener('click',closeCategoryPicker));
  categoryModal?.addEventListener('click',event=>{if(event.target===categoryModal)closeCategoryPicker()});
  categorySearch?.addEventListener('input',()=>{pendingCategoryId=null;renderCategoryPicker()});
  confirmCategory?.addEventListener('click',()=>{
    const category=categoryById.get(pendingCategoryId);if(!category||!isCategoryLeaf(category))return;
    if(categoryMode==='primary'){
      const field=q('[data-primary-category-id]');if(field)field.value=String(category.id);
      q('[data-primary-category-path]').textContent=category.path;
      const card=q('[data-selected-primary-category]');card?.classList.remove('is-empty');
      card?.classList.remove('is-invalid');
      const label=q('.selected-category__label',card);if(label)label.textContent='Categoria principal';
      const button=q('[data-open-category-picker="primary"]',card);if(button){button.textContent='Alterar';button.dataset.categoryParentHint=''}
      additionalCategoryIds=additionalCategoryIds.filter(id=>id!==category.id);renderAdditionalCategories();
    }else{
      if(category.id===primaryCategoryId()){alert('Esta já é a categoria principal do produto.');return}
      if(additionalCategoryIds.length>=3){alert('Você pode adicionar no máximo 3 categorias adicionais.');return}
      if(!additionalCategoryIds.includes(category.id))additionalCategoryIds.push(category.id);renderAdditionalCategories();
    }
    closeCategoryPicker();updateQuality();updateReview();
  });
  document.addEventListener('keydown',event=>{if(event.key==='Escape'&&categoryModal&&!categoryModal.hidden)closeCategoryPicker()});

  const updateTagCount=()=>{
    const selected=qa('[data-tag-option]:checked');
    const counter=q('[data-selected-tag-count]');if(counter)counter.textContent=`${selected.length}/10`;
    return selected.length;
  };
  qa('[data-tag-option]').forEach(option=>option.addEventListener('change',()=>{if(updateTagCount()>10){option.checked=false;updateTagCount();alert('Escolha no máximo 10 tags por produto.')}}));
  updateTagCount();
  const updateReview=()=>{
    const typeLabels={simple:'Produto simples',variable:'Produto com variações',kit:'Kit de produtos'};
    q('[data-review-name]').textContent=nameField?.value||'Nome não informado';
    q('[data-review-type]').textContent=typeLabels[q('[name="product_type"]:checked')?.value]||'';
    q('[data-review-price]').textContent=Number(q('[name="price"]')?.value)>0?money(q('[name="promotional_price"]')?.value||q('[name="price"]')?.value):'Preço não informado';
    q('[data-review-sale]').textContent=[q('[name="retail_enabled"]')?.checked?'Varejo':'',q('[name="wholesale_enabled"]')?.checked?'Atacado':''].filter(Boolean).join(' + ')||'Modalidade não escolhida';
    const stock=Number(q('[name="stock"]')?.value||variants.reduce((sum,row)=>sum+Number(row.stock||0),0));q('[data-review-stock]').textContent=`${stock} unidades`;
    q('[data-review-media]').textContent=`${existingImages()+mediaQueue.filter(item=>item.type==='image').length} imagens`;
    const seoTitle=q('[name="seo_title"]')?.value||nameField?.value||'Título do seu produto';q('[data-seo-preview-title]').textContent=seoTitle;
    q('[data-seo-preview-description]').textContent=q('[name="seo_description"]')?.value||q('[name="short_description"]')?.value||'A descrição do produto aparecerá aqui.';
  };
  const updateQuality=()=>{
    const imageCount=existingImages()+mediaQueue.filter(item=>item.type==='image').length;
    const stock=Number(q('[name="stock"]')?.value||variants.reduce((sum,row)=>sum+Number(row.stock||0),0));
    const descriptionLength=(richContent?.innerText||q('[name="description"]')?.value||'').trim().length;
    const seoConfigured=Boolean(seoTitleField?.value.trim()&&seoDescriptionField?.value.trim()&&seoKeywordsField?.value.trim());
    const states={name:(nameField?.value.trim().length||0)>=20,category:!!q('[name="primary_category_id"]')?.value,description:descriptionLength>=120,specification:specificationRows.some(row=>row.name&&row.value),price:Number(q('[name="price"]')?.value)>0,stock:stock>0||q('[name="allow_backorder"]')?.checked,shipping:Number(q('[name="weight"]')?.value)>0&&Number(q('[name="width"]')?.value)>0&&Number(q('[name="height"]')?.value)>0&&Number(q('[name="length"]')?.value)>0,media:imageCount>=4,video:mediaQueue.some(item=>item.type==='video')||!!q('[data-video-preview] video'),seo:seoConfigured};
    const weights={name:10,category:10,description:15,specification:10,price:10,stock:10,shipping:10,media:15,video:5,seo:5};
    const score=Object.entries(weights).reduce((sum,[key,weight])=>sum+(states[key]?weight:0),0);
    qa('[data-quality-percent]').forEach(node=>{node.textContent=`${score}%`;node.dataset.value=`${score}%`});
    q('[data-quality-ring]')?.style.setProperty('--quality',`${score}%`);q('[data-quality-progress]').style.width=`${score}%`;
    ['name','price','stock','shipping','category','description','media','seo'].forEach(key=>q(`[data-quality-check="${key}"]`)?.classList.toggle('is-complete',!!states[key]));
    stepButtons.forEach(button=>button.classList.toggle('is-complete',Number(button.dataset.productStepButton)<currentStep));
  };
  editor.addEventListener('input',()=>{updateQuality();updateReview()});editor.addEventListener('change',()=>{updateModeVisibility();updateQuality();updateReview()});
  editor.addEventListener('submit',async event=>{
    syncAutoFields();syncRichText();
    if(!q('[data-primary-category-id]')?.value){
      event.preventDefault();
      const trigger=q('[data-open-category-picker="primary"]');
      openCategoryPicker('primary',trigger?.dataset.categoryParentHint);
      return;
    }
    q('[data-variants-json]').value=JSON.stringify(variants);q('[data-wholesale-json]').value=JSON.stringify(wholesaleRows);q('[data-specifications-json]').value=JSON.stringify(specificationRows);q('[data-shipping-json]').value=JSON.stringify(shippingRows);
    syncMediaState();
    if(readyToSubmit)return;
    if(mediaQueue.some(item=>item.status!=='uploaded')){event.preventDefault();const submitter=event.submitter;editor.classList.add('is-saving');try{await uploadQueuedMedia();readyToSubmit=true;editor.requestSubmit(submitter)}catch(error){alert(error.message);editor.classList.remove('is-saving')}}
  });
  qa('[data-media-key]',gallery).forEach(bindMediaCard);syncAutoFields();syncRichText();syncMediaState();renderAdditionalCategories();renderWholesale();renderSpecifications();renderShipping();renderVariants();updateModeVisibility();updatePrices();updateQuality();updateReview();
});

document.querySelectorAll('[data-store-settings]').forEach(form=>{
  const name=form.querySelector('[data-store-name]');
  const description=form.querySelector('[data-store-description]');
  const logoInput=form.querySelector('[data-store-logo-input]');
  const bannerInput=form.querySelector('[data-store-banner-input]');
  const updateText=()=>{
    form.querySelector('[data-store-name-preview]').textContent=name.value||'Nome da loja';
    form.querySelector('[data-store-description-preview]').textContent=description.value||'Adicione uma descrição para apresentar sua loja.';
    form.querySelector('[data-store-description-count]').textContent=String(description.value.length);
  };
  const previewFile=(input,callback)=>{
    const file=input.files?.[0];if(!file)return;
    if(!['image/jpeg','image/png','image/webp'].includes(file.type)){alert('Use uma imagem JPG, PNG ou WebP.');input.value='';return}
    const url=URL.createObjectURL(file);callback(url,file);
  };
  logoInput?.addEventListener('change',()=>previewFile(logoInput,(url,file)=>{
    form.querySelector('[data-logo-upload-preview]').innerHTML=`<img src="${url}" alt="Prévia da logo">`;
    form.querySelector('[data-store-logo-preview]').innerHTML=`<img src="${url}" alt="">`;
    const remove=form.querySelector('[data-remove-store-logo]');if(remove)remove.checked=false;
    form.querySelector('[data-store-upload="logo"] p').textContent=file.name;
  }));
  bannerInput?.addEventListener('change',()=>previewFile(bannerInput,(url,file)=>{
    form.querySelector('[data-banner-upload-preview]').innerHTML=`<img src="${url}" alt="Prévia do banner">`;
    form.querySelector('[data-store-banner-preview]').style.backgroundImage=`url("${url}")`;
    const remove=form.querySelector('[data-remove-store-banner]');if(remove)remove.checked=false;
    form.querySelector('[data-store-upload="banner"] p').textContent=file.name;
  }));
  form.querySelector('[data-remove-store-logo]')?.addEventListener('change',event=>{if(event.target.checked){logoInput.value='';form.querySelector('[data-store-logo-preview]').innerHTML=`<b>${(name.value||'L').slice(0,1).toUpperCase()}</b>`;form.querySelector('[data-logo-upload-preview]').innerHTML='<b>Sem logo</b>'}});
  form.querySelector('[data-remove-store-banner]')?.addEventListener('change',event=>{if(event.target.checked){bannerInput.value='';form.querySelector('[data-store-banner-preview]').style.backgroundImage='';form.querySelector('[data-banner-upload-preview]').innerHTML='<span>Sem banner</span>'}});
  [name,description].forEach(field=>field?.addEventListener('input',updateText));
});

document.querySelectorAll('[data-platform-settings-form]').forEach(form=>{
  const page=form.closest('[data-platform-settings-page]');
  const savebar=form.querySelector('[data-settings-savebar]');
  const indicators=[...page.querySelectorAll('[data-unsaved-indicator]')];
  const discardButtons=[...page.querySelectorAll('[data-settings-discard]')];
  const submitButtons=[...page.querySelectorAll('[data-settings-submit]')];
  let dirty=false;
  let submitting=false;
  const setDirty=value=>{dirty=value;indicators.forEach(item=>item.hidden=!value);discardButtons.forEach(button=>button.disabled=!value);if(savebar)savebar.hidden=!value};
  const changed=()=>setDirty(true);
  form.addEventListener('input',changed);
  form.addEventListener('change',changed);
  discardButtons.forEach(button=>button.addEventListener('click',()=>{if(!dirty||confirm('Descartar todas as alterações desta área?'))location.reload()}));
  page.querySelectorAll('.platform-settings__tabs a').forEach(link=>link.addEventListener('click',event=>{if(dirty&&!confirm('Existem alterações não salvas. Deseja sair mesmo assim?'))event.preventDefault()}));
  window.addEventListener('beforeunload',event=>{if(!dirty||submitting)return;event.preventDefault();event.returnValue=''});
  form.addEventListener('submit',()=>{submitting=true;page.classList.add('is-saving');submitButtons.forEach(button=>{button.disabled=true;button.textContent='Salvando…'})});

  const previewText=(field,selector,fallback)=>field?.addEventListener('input',()=>page.querySelectorAll(selector).forEach(item=>item.textContent=field.value||fallback));
  previewText(form.querySelector('[data-preview-name]'),'[data-preview-name]','Tuffer');
  previewText(form.querySelector('[data-preview-slogan]'),'[data-preview-slogan]','LOJA OFICIAL');
  previewText(form.querySelector('[data-preview-description]'),'[data-preview-description]','Um marketplace feito para comprar de quem faz.');
  previewText(form.querySelector('[data-seo-title]'),'[data-seo-title-preview]','Tuffer Marketplace');
  previewText(form.querySelector('[data-seo-description]'),'[data-seo-description-preview]','Conheça produtos, ofertas e lojas selecionadas na Tuffer.');
  form.querySelectorAll('.platform-color-field').forEach(field=>{
    const picker=field.querySelector('[data-color-picker]');const text=field.querySelector('[data-color-text]');
    const sync=(source,target)=>{target.value=source.value;const preview=page.querySelector('[data-platform-preview]');if(preview){const property=text.name==='primary_color'?'--preview-primary':text.name==='secondary_color'?'--preview-secondary':'--preview-accent';preview.style.setProperty(property,text.value)}};
    picker?.addEventListener('input',()=>{sync(picker,text);changed()});text?.addEventListener('input',()=>{if(/^#[0-9a-f]{6}$/i.test(text.value))sync(text,picker)});
  });
  form.querySelector('[data-button-style]')?.addEventListener('change',event=>{const button=page.querySelector('[data-preview-button]');if(button)button.style.borderRadius=event.target.value==='square'?'3px':event.target.value==='soft'?'10px':'99px'});

  const setFiles=(input,files)=>{const transfer=new DataTransfer();files.slice(0,1).forEach(file=>transfer.items.add(file));input.files=transfer.files;input.dispatchEvent(new Event('change',{bubbles:true}))};
  form.querySelectorAll('[data-platform-upload]').forEach(card=>{
    const input=card.querySelector('[data-upload-input]');const preview=card.querySelector('[data-upload-preview]');const filename=card.querySelector('[data-upload-filename]');const removeValue=card.querySelector('[data-remove-value]');const removeButton=card.querySelector('[data-remove-upload]');
    const showFile=file=>{card.classList.remove('has-error','is-removed');const maximum=Number(input.dataset.maxBytes||0);if(!['image/jpeg','image/png','image/webp','image/gif','image/x-icon','image/vnd.microsoft.icon'].includes(file.type)||maximum&&file.size>maximum){input.value='';card.classList.add('has-error');filename.textContent=`Arquivo inválido. Use PNG, JPG ou WebP com até ${Math.ceil(maximum/1048576)} MB.`;return}const objectUrl=URL.createObjectURL(file);preview.innerHTML='';const image=document.createElement('img');image.src=objectUrl;image.alt='Prévia da imagem';preview.append(image);filename.textContent=file.name;removeValue.value='0';removeButton.hidden=false;if(card.dataset.previewKey==='logo')page.querySelectorAll('[data-preview-logo]').forEach(target=>{target.innerHTML='';const clone=image.cloneNode();clone.alt='';target.append(clone)});if(card.dataset.previewKey==='favicon')page.querySelectorAll('[data-preview-favicon]').forEach(target=>{target.innerHTML='';const clone=image.cloneNode();clone.alt='';target.append(clone)})};
    input?.addEventListener('change',()=>{const file=input.files?.[0];if(file)showFile(file)});
    removeButton?.addEventListener('click',()=>{input.value='';removeValue.value='1';card.classList.add('is-removed');preview.innerHTML='<span>Imagem removida</span>';filename.textContent='A remoção será aplicada ao salvar';removeButton.hidden=true;changed();if(card.dataset.previewKey==='logo')page.querySelectorAll('[data-preview-logo]').forEach(target=>target.textContent='TUFFER');if(card.dataset.previewKey==='favicon')page.querySelectorAll('[data-preview-favicon]').forEach(target=>target.textContent='T')});
    ['dragenter','dragover'].forEach(type=>card.addEventListener(type,event=>{event.preventDefault();card.classList.add('is-dragging')}));
    ['dragleave','drop'].forEach(type=>card.addEventListener(type,event=>{event.preventDefault();card.classList.remove('is-dragging');if(type==='drop'){const images=[...event.dataTransfer.files].filter(file=>file.type.startsWith('image/'));if(images.length)setFiles(input,images)}}));
    card.addEventListener('paste',event=>{const images=[...(event.clipboardData?.files||[])].filter(file=>file.type.startsWith('image/'));if(images.length){event.preventDefault();setFiles(input,images)}});
  });
  setDirty(false);
});

document.querySelectorAll('[data-coupon-editor]').forEach(form=>{
  const code=form.querySelector('[data-coupon-code]');
  const campaignName=form.querySelector('[data-coupon-name]');
  const value=form.querySelector('[data-coupon-value]');
  const minimum=form.querySelector('[data-coupon-minimum]');
  const start=form.querySelector('[data-coupon-start]');
  const end=form.querySelector('[data-coupon-end]');
  const formatCurrency=number=>Number(number||0).toLocaleString('pt-BR',{style:'currency',currency:'BRL'});
  const update=()=>{
    const type=form.querySelector('[name="discount_type"]:checked')?.value||'percentage';
    code.value=code.value.toUpperCase().replace(/[^A-Z0-9_-]/g,'');
    form.querySelector('[data-discount-prefix]').textContent=type==='percentage'?'%':'R$';
    form.querySelector('[data-preview-kind]').textContent=type==='percentage'?'DESCONTO EXCLUSIVO':'CRÉDITO NA COMPRA';
    form.querySelector('[data-preview-value]').textContent=type==='percentage'?`${Number(value.value||0).toLocaleString('pt-BR')}% OFF`:`${formatCurrency(value.value)} OFF`;
    form.querySelector('[data-preview-code]').textContent=code.value||'SEUCODIGO';
    form.querySelector('[data-preview-name]').textContent=campaignName.value||'Nome da campanha';
    form.querySelector('[data-preview-rule]').textContent=Number(minimum.value)>0?`Válido para compras a partir de ${formatCurrency(minimum.value)}`:'Válido para qualquer valor de compra';
    value.max=type==='percentage'?'100':'';
    if(start.value&&end.value&&new Date(end.value)<=new Date(start.value))end.setCustomValidity('O encerramento precisa ser posterior ao início.');else end.setCustomValidity('');
  };
  form.querySelectorAll('input,select,textarea').forEach(field=>{field.addEventListener('input',update);field.addEventListener('change',update)});
  update();
});

document.querySelector('[data-coupon-search]')?.addEventListener('input',event=>{
  const term=event.target.value.trim().toLowerCase();
  document.querySelectorAll('[data-coupon-name]').forEach(row=>row.hidden=!row.dataset.couponName.includes(term));
});

const onlyDigits=value=>value.replace(/\D/g,'');
const masks={
  cnpj:value=>onlyDigits(value).slice(0,14).replace(/^(\d{2})(\d)/,'$1.$2').replace(/^(\d{2})\.(\d{3})(\d)/,'$1.$2.$3').replace(/\.(\d{3})(\d)/,'.$1/$2').replace(/(\d{4})(\d)/,'$1-$2'),
  cpf:value=>onlyDigits(value).slice(0,11).replace(/^(\d{3})(\d)/,'$1.$2').replace(/^(\d{3})\.(\d{3})(\d)/,'$1.$2.$3').replace(/(\d{3})(\d{1,2})$/,'$1-$2'),
  cep:value=>onlyDigits(value).slice(0,8).replace(/(\d{5})(\d)/,'$1-$2'),
  phone:value=>onlyDigits(value).slice(0,11).replace(/^(\d{2})(\d)/,'($1) $2').replace(/(\d{5})(\d{4})$/,'$1-$2'),
};
document.querySelectorAll('[data-mask]').forEach(input=>{const apply=()=>{const mask=masks[input.dataset.mask];if(mask)input.value=mask(input.value)};input.addEventListener('input',apply);apply()});
document.querySelectorAll('[data-format-cnpj]').forEach(element=>element.textContent=masks.cnpj(element.textContent));
const ieStatus=document.querySelector('[data-ie-status]');
const ieField=document.querySelector('[data-ie-field]');
const updateIe=()=>{if(ieField){ieField.hidden=ieStatus?.value!=='taxpayer';const input=ieField.querySelector('input');if(input)input.required=ieStatus?.value==='taxpayer'}};
ieStatus?.addEventListener('change',updateIe);updateIe();

document.querySelectorAll('[data-checkout]').forEach(form=>{
  const q=(selector,root=form)=>root.querySelector(selector);
  const qa=(selector,root=form)=>[...root.querySelectorAll(selector)];
  const money=value=>Number(value||0).toLocaleString('pt-BR',{style:'currency',currency:'BRL'});
  const escape=value=>String(value??'').replace(/[&<>'"]/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[char]));
  const cards=Object.fromEntries(qa('[data-checkout-step]').map(card=>[card.dataset.checkoutStep,card]));
  const open=(card,scroll=true)=>{if(!card||card.classList.contains('is-locked'))return;qa('[data-checkout-step]').forEach(item=>item.classList.toggle('is-open',item===card));if(scroll)card.scrollIntoView({behavior:'smooth',block:'center'})};
  qa('[data-step-toggle]').forEach(button=>button.addEventListener('click',()=>open(button.closest('[data-checkout-step]'))));
  const shippingComplete=()=>{const stores=qa('[data-shipping-store]');return stores.length>0&&stores.every(store=>!!q('input[type="radio"]:checked',store))};
  const refreshTotals=()=>{
    const shipping=qa('[data-shipping-price]:checked').reduce((sum,input)=>sum+Number(input.dataset.shippingPrice||0),0);
    const base=Number(q('[data-summary-total]')?.dataset.baseTotal||0);const total=base+shipping;
    if(q('[data-summary-shipping]'))q('[data-summary-shipping]').textContent=shipping?money(shipping):'Selecionar';
    if(q('[data-summary-total]'))q('[data-summary-total]').textContent=money(total);
    if(q('[data-installment]'))q('[data-installment]').textContent=`ou 3x de ${money(total/3)} sem juros`;
    document.querySelectorAll('[data-mobile-total]').forEach(item=>item.textContent=money(total));
    refreshAction(total);
  };
  const refreshAction=total=>{
    const submit=q('[data-checkout-submit]');if(!submit)return;
    const hasAddress=!!q('[name="address_id"]:checked');const hasShipping=shippingComplete();const terms=!!q('[name="terms"]:checked');const paymentConfigured=form.dataset.paymentConfigured==='1';const customer=form.dataset.customer==='1';
    const ready=customer&&hasAddress&&hasShipping&&terms&&paymentConfigured;
    submit.disabled=!ready;submit.classList.toggle('is-ready',ready);submit.classList.toggle('button--primary',ready);submit.classList.toggle('button--secondary',!ready);
    if(ready)submit.textContent=`Finalizar compra — ${money(total)}`;else if(!customer)submit.textContent='Entre para continuar';else if(!hasAddress)submit.textContent='Selecione um endereço';else if(!hasShipping)submit.textContent='Selecione a entrega de cada loja';else if(!paymentConfigured)submit.textContent='Pagamento em configuração';else submit.textContent='Aceite os termos para continuar';
    const mobile=document.querySelector('[data-mobile-submit]');if(mobile){mobile.disabled=!ready;mobile.textContent=ready?'Finalizar':'Continuar';mobile.classList.toggle('button--primary',ready);mobile.classList.toggle('button--secondary',!ready)}
  };
  const bindShipping=()=>qa('[data-shipping-price]').forEach(input=>input.addEventListener('change',()=>{cards.shipping?.classList.toggle('is-complete',shippingComplete());if(shippingComplete()){const summary=q('[data-step-summary]',cards.shipping);if(summary)summary.textContent='Modalidades selecionadas para todas as lojas';open(cards.payment)}refreshTotals()}));
  const renderShipping=state=>{
    qa('[data-shipping-store]').forEach(store=>{const storeId=store.dataset.shippingStore;const data=state.stores?.[storeId];const root=q('.shipping-options',store);const message=q('header>small',store);if(message)message.textContent=data?.message||'';if(!root)return;root.innerHTML=(data?.options||[]).map((option,index)=>`<label><input type="radio" name="shipping[${escape(storeId)}]" value="${escape(option.id)}" data-shipping-price="${escape(option.price)}" ${index===0?'checked':''}><span><b>${escape(option.service)}</b><small>${escape(option.carrier)} · chega entre ${escape(option.arrival_min)} e ${escape(option.arrival_max)}</small></span><strong>${money(option.price)}</strong></label>`).join('');if(!root.children.length)root.innerHTML=`<p class="shipping-empty">${escape(data?.message||state.message||'Cotação indisponível.')}</p>`});
    cards.shipping?.classList.toggle('is-complete',shippingComplete());bindShipping();refreshTotals();open(shippingComplete()?cards.payment:cards.shipping);
  };
  const quoteAddress=async input=>{
    if(!input)return;const summary=q('[data-step-summary]',cards.address);if(summary)summary.textContent=input.dataset.addressSummary||'Endereço selecionado';cards.address?.classList.add('is-complete');cards.shipping?.classList.remove('is-locked','is-complete');open(cards.shipping);const shippingSummary=q('[data-step-summary]',cards.shipping);if(shippingSummary)shippingSummary.textContent='Calculando opções reais...';
    const body=new FormData();body.append('_token',q('[name="_token"]')?.value||'');body.append('postal_code',input.dataset.postalCode||'');
    try{const response=await fetch(form.dataset.quotesUrl,{method:'POST',body,headers:{'X-Requested-With':'XMLHttpRequest'}});if(!response.ok)throw new Error();renderShipping(await response.json())}catch{if(shippingSummary)shippingSummary.textContent='Não foi possível calcular a entrega agora.'}
  };
  qa('[name="address_id"]').forEach(input=>input.addEventListener('change',()=>quoteAddress(input)));
  qa('[name="payment_method"]').forEach(input=>input.addEventListener('change',()=>{qa('[data-payment-panel]').forEach(panel=>panel.hidden=panel.dataset.paymentPanel!==input.value);const summary=q('[data-step-summary]',cards.payment);if(summary)summary.textContent=input.closest('label')?.querySelector('b')?.textContent||'Pagamento selecionado';cards.payment?.classList.add('is-complete');refreshTotals()}));
  q('[name="terms"]')?.addEventListener('change',refreshTotals);
  document.querySelector('[data-mobile-submit]')?.addEventListener('click',()=>form.requestSubmit());
  let checkoutSubmitting=false;
  form.addEventListener('submit',event=>{
    if(checkoutSubmitting){event.preventDefault();return}
    checkoutSubmitting=true;
    const submit=q('[data-checkout-submit]');if(submit){submit.disabled=true;submit.textContent='Criando pedido…'}
    const mobile=document.querySelector('[data-mobile-submit]');if(mobile)mobile.disabled=true;
  });
  bindShipping();refreshTotals();
  if(!q('[name="address_id"]:checked'))open(cards.address,false);else if(!shippingComplete())open(cards.shipping,false);else open(cards.payment,false);
});

document.querySelectorAll('[data-copy-target]').forEach(button=>button.addEventListener('click',async()=>{
  const target=document.querySelector(button.dataset.copyTarget||'');if(!target)return;
  try{await navigator.clipboard.writeText(target.value||target.textContent||'');button.textContent='Copiado'}catch{target.select?.()}
}));

document.querySelectorAll('[data-export-upload]').forEach(form=>{
  const input=form.querySelector('[data-export-file]');
  const label=form.querySelector('[data-export-file-label]');
  input?.addEventListener('change',()=>{if(label)label.textContent=input.files?.[0]?.name||'Escolher CSV, SQL ou XML'});
});

document.querySelectorAll('[data-export-organizer]').forEach(form=>{
  const list=form.querySelector('[data-export-columns-list]');
  const columnItems=()=>[...(list?.querySelectorAll('[data-export-column]')||[])];
  const refreshColumnButtons=()=>columnItems().forEach((item,index,items)=>{
    const up=item.querySelector('[data-column-up]');const down=item.querySelector('[data-column-down]');
    if(up)up.disabled=index===0;if(down)down.disabled=index===items.length-1;
  });
  list?.addEventListener('click',event=>{
    const button=event.target.closest('[data-column-up],[data-column-down]');if(!button)return;
    const item=button.closest('[data-export-column]');
    if(button.matches('[data-column-up]')&&item.previousElementSibling)list.insertBefore(item,item.previousElementSibling);
    if(button.matches('[data-column-down]')&&item.nextElementSibling)list.insertBefore(item.nextElementSibling,item);
    refreshColumnButtons();
  });
  const toggle=form.querySelector('[data-export-columns-toggle]');
  toggle?.addEventListener('click',()=>{
    const inputs=columnItems().map(item=>item.querySelector('input[type="checkbox"]')).filter(Boolean);
    const shouldCheck=inputs.every(input=>!input.checked);inputs.forEach(input=>input.checked=shouldCheck);toggle.textContent=shouldCheck?'Desmarcar todas':'Marcar todas';
  });
  const allRows=form.querySelector('[data-export-rows-all]');const rows=[...form.querySelectorAll('[data-export-row]')];
  allRows?.addEventListener('change',()=>rows.forEach(input=>input.checked=allRows.checked));
  rows.forEach(input=>input.addEventListener('change',()=>{if(allRows){allRows.checked=rows.every(row=>row.checked);allRows.indeterminate=!allRows.checked&&rows.some(row=>row.checked)}}));
  form.querySelectorAll('[name="row_mode"]').forEach(input=>input.addEventListener('change',()=>{
    const selected=form.querySelector('[name="row_mode"]:checked')?.value==='selected';
    rows.forEach(row=>row.disabled=!selected);if(allRows)allRows.disabled=!selected;
  }));
  rows.forEach(row=>row.disabled=true);if(allRows)allRows.disabled=true;refreshColumnButtons();
  form.addEventListener('submit',event=>{
    if(!columnItems().some(item=>item.querySelector('input')?.checked)){event.preventDefault();alert('Selecione ao menos uma coluna para exportar.');return}
    if(form.querySelector('[name="row_mode"]:checked')?.value==='selected'&&!rows.some(row=>row.checked)){event.preventDefault();alert('Selecione ao menos uma linha para exportar.')}
  });
});

document.querySelectorAll('[data-product-list]').forEach(table=>{
  const form=document.querySelector('[data-product-bulk-form]');
  const selectAll=table.querySelector('[data-products-select-all]');
  const selections=[...table.querySelectorAll('[data-product-select]')];
  const counter=form?.querySelector('[data-product-selected-count]');
  const refresh=()=>{
    const selected=selections.filter(input=>input.checked);
    if(form)form.hidden=selected.length===0;
    if(counter)counter.textContent=String(selected.length);
    selections.forEach(input=>input.closest('[data-product-row]')?.classList.toggle('is-selected',input.checked));
    if(selectAll){selectAll.checked=selections.length>0&&selected.length===selections.length;selectAll.indeterminate=selected.length>0&&selected.length<selections.length}
  };
  selectAll?.addEventListener('change',()=>{selections.forEach(input=>input.checked=selectAll.checked);refresh()});
  selections.forEach(input=>input.addEventListener('change',refresh));
  form?.querySelector('[data-product-selection-clear]')?.addEventListener('click',()=>{selections.forEach(input=>input.checked=false);refresh()});
  form?.addEventListener('submit',event=>{
    const count=selections.filter(input=>input.checked).length;
    const action=event.submitter?.dataset.bulkAction||'delete';
    if(!count){event.preventDefault();return}
    if(action==='status'){
      const status=form.querySelector('[data-product-bulk-status]');
      if(!status?.value){event.preventDefault();alert('Escolha o novo status dos produtos.');status?.focus();return}
      const label=status.options[status.selectedIndex].text;
      if(!confirm(`Alterar ${count} produto(s) para ${label}?`))event.preventDefault();
      return;
    }
    if(action==='duplicate'||action==='move'){
      const target=form.querySelector('[data-product-target-store]');
      if(!target?.value){event.preventDefault();alert('Escolha a loja de destino.');target?.focus();return}
      const storeName=target.options[target.selectedIndex].text;
      const verb=action==='duplicate'?'Duplicar':'Mover';
      const warning=action==='move'?' Produtos com pedidos não podem ser movidos para preservar o histórico.':'';
      if(!confirm(`${verb} ${count} produto(s) para ${storeName}?${warning}`))event.preventDefault();
      return;
    }
    if(!confirm(`Excluir ${count} produto(s) selecionado(s)? Produtos com pedidos serão arquivados.`))event.preventDefault();
  });
  refresh();
});

document.querySelectorAll('form[data-confirm]').forEach(form=>form.addEventListener('submit',event=>{
  if(!confirm(form.dataset.confirm||'Confirma esta ação?'))event.preventDefault();
}));

document.querySelectorAll('[data-category-editor]').forEach(editor=>{
  const name=editor.querySelector('[data-category-name]');
  const slug=editor.querySelector('[data-category-slug]');
  const parent=editor.querySelector('[data-category-parent]');
  const order=editor.querySelector('[data-category-order]');
  const status=editor.querySelector('[data-category-status]');
  const support=editor.querySelector('[data-category-support]');
  const upload=editor.querySelector('[data-category-upload]');
  const imageInput=editor.querySelector('[data-category-image-input]');
  const removeImage=editor.querySelector('[data-remove-category-image]');
  const removeValue=editor.querySelector('[data-remove-category-image-value]');
  const preview=editor.querySelector('[data-category-image-preview]');
  const summaryImage=editor.querySelector('[data-category-summary-image]');
  const filename=editor.querySelector('[data-category-image-name]');
  let slugTouched=!!slug?.value;
  let objectUrl='';
  const slugify=value=>String(value||'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'');
  const renderSlug=()=>{const target=editor.querySelector('[data-category-slug-preview]');if(target)target.textContent=`/categoria/${slugify(slug?.value)||'nova-categoria'}`};
  const initial=()=>String(name?.value||'C').trim().charAt(0).toUpperCase()||'C';
  const renderFallback=()=>{
    [preview,summaryImage].forEach(target=>{if(target){target.innerHTML='';const letter=document.createElement('span');letter.textContent=initial();target.append(letter)}});
  };
  const setPreview=file=>{
    if(!file)return;
    if(!['image/jpeg','image/png','image/webp'].includes(file.type)||file.size>2*1024*1024){imageInput.value='';upload?.classList.add('has-error');if(filename)filename.textContent='Arquivo inválido. Use JPG, PNG ou WebP de até 2 MB.';return}
    upload?.classList.remove('has-error');if(objectUrl)URL.revokeObjectURL(objectUrl);objectUrl=URL.createObjectURL(file);
    [preview,summaryImage].forEach(target=>{if(target){target.innerHTML='';const image=document.createElement('img');image.src=objectUrl;image.alt='Prévia da categoria';target.append(image)}});
    if(filename)filename.textContent=file.name;if(removeValue)removeValue.value='0';if(removeImage)removeImage.hidden=false;
    const title=editor.querySelector('[data-category-upload-title]');if(title)title.textContent='Nova imagem pronta para salvar';
  };
  name?.addEventListener('input',()=>{
    if(!slugTouched&&slug){slug.value=slugify(name.value);renderSlug()}
    const title=editor.querySelector('[data-category-name-preview]');if(title)title.textContent=name.value||'Nova categoria';
    if(!preview?.querySelector('img'))renderFallback();
  });
  slug?.addEventListener('input',()=>{slugTouched=slug.value.trim()!=='';renderSlug()});
  parent?.addEventListener('change',()=>{const target=editor.querySelector('[data-category-parent-preview]');if(target)target.textContent=parent.selectedOptions[0]?.dataset.path||'Categoria principal'});
  order?.addEventListener('input',()=>{const target=editor.querySelector('[data-category-order-preview]');if(target)target.textContent=order.value||'0'});
  status?.addEventListener('change',()=>{const target=editor.querySelector('[data-category-status-preview]');if(target){const active=status.value==='active';target.textContent=active?'Ativa':'Inativa';target.classList.toggle('is-active',active)}});
  support?.addEventListener('input',()=>{const target=editor.querySelector('[data-category-support-preview]');if(target)target.textContent=support.value||'Adicione um texto curto para apresentar esta categoria.'});
  imageInput?.addEventListener('change',()=>setPreview(imageInput.files?.[0]));
  removeImage?.addEventListener('click',()=>{imageInput.value='';if(removeValue)removeValue.value='1';removeImage.hidden=true;if(filename)filename.textContent='A imagem será removida ao salvar';renderFallback()});
  const setDroppedFile=file=>{if(!imageInput||!file)return;const transfer=new DataTransfer();transfer.items.add(file);imageInput.files=transfer.files;setPreview(file)};
  ['dragenter','dragover'].forEach(type=>upload?.addEventListener(type,event=>{event.preventDefault();upload.classList.add('is-dragging')}));
  ['dragleave','drop'].forEach(type=>upload?.addEventListener(type,event=>{event.preventDefault();upload.classList.remove('is-dragging');if(type==='drop')setDroppedFile([...event.dataTransfer.files].find(file=>file.type.startsWith('image/')))}));
});

document.querySelectorAll('[data-category-carousel]').forEach(carousel=>{
  const track=carousel.querySelector('[data-category-track]');
  const move=direction=>{const card=track?.querySelector('.home-category-card');if(!track||!card)return;const gap=Number.parseFloat(getComputedStyle(track).columnGap||getComputedStyle(track).gap)||14;track.scrollBy({left:direction*(card.getBoundingClientRect().width+gap)*2,behavior:'smooth'})};
  carousel.querySelector('[data-category-previous]')?.addEventListener('click',()=>move(-1));
  carousel.querySelector('[data-category-next]')?.addEventListener('click',()=>move(1));
});

document.querySelectorAll('[data-home-carousel]').forEach(carousel=>{
  const track=carousel.querySelector('[data-carousel-track]');
  const previous=carousel.querySelector('[data-carousel-previous]');
  const next=carousel.querySelector('[data-carousel-next]');
  const dots=carousel.querySelector('[data-carousel-dots]');
  const controls=carousel.querySelector('[data-carousel-controls]');
  const mobile=matchMedia('(max-width:700px)');
  const reducedMotion=matchMedia('(prefers-reduced-motion:reduce)');
  const autoplay=Number.parseInt(carousel.dataset.carouselAutoplay||'0',10);
  let page=0,pages=1,visible=1,scrollFrame=0,timer=0;
  const items=()=>[...(track?.children||[])];
  const setButtons=()=>{
    if(previous)previous.disabled=page<=0;
    if(next)next.disabled=page>=pages-1;
    dots?.querySelectorAll('button').forEach((dot,index)=>{
      dot.classList.toggle('is-active',index===page);
      dot.setAttribute('aria-current',index===page?'true':'false');
    });
  };
  const goTo=(target,smooth=true)=>{
    const all=items();
    page=Math.max(0,Math.min(pages-1,target));
    const item=all[Math.min(all.length-1,page*visible)];
    if(item&&track){
      const left=track.scrollLeft+item.getBoundingClientRect().left-track.getBoundingClientRect().left;
      track.scrollTo({left,behavior:smooth&&!reducedMotion.matches?'smooth':'auto'});
    }
    setButtons();
  };
  const buildDots=()=>{
    if(!dots)return;
    dots.replaceChildren();
    for(let index=0;index<pages;index++){
      const dot=document.createElement('button');
      dot.type='button';dot.setAttribute('aria-label',`Ir para a página ${index+1} de ${pages}`);
      dot.addEventListener('click',()=>goTo(index));dots.append(dot);
    }
  };
  const refresh=()=>{
    if(!track||!mobile.matches){page=0;pages=1;visible=1;dots?.replaceChildren();if(controls)controls.hidden=true;return}
    const all=items();
    const first=all[0];
    if(!first)return;
    const gap=Number.parseFloat(getComputedStyle(track).columnGap||getComputedStyle(track).gap)||0;
    const step=first.getBoundingClientRect().width+gap;
    visible=Math.max(1,Math.floor((track.clientWidth+gap+.5)/step));
    const calculated=Math.max(1,Math.ceil(all.length/visible));
    if(calculated!==pages){pages=calculated;page=Math.min(page,pages-1);buildDots()}
    if(controls)controls.hidden=pages<=1;
    setButtons();
  };
  const stopAutoplay=()=>{if(timer){clearInterval(timer);timer=0}};
  const startAutoplay=()=>{
    stopAutoplay();
    if(!mobile.matches||reducedMotion.matches||autoplay<=0||pages<=1)return;
    timer=setInterval(()=>goTo(page>=pages-1?0:page+1),autoplay);
  };
  previous?.addEventListener('click',()=>{goTo(page-1);startAutoplay()});
  next?.addEventListener('click',()=>{goTo(page+1);startAutoplay()});
  track?.addEventListener('scroll',()=>{
    cancelAnimationFrame(scrollFrame);
    scrollFrame=requestAnimationFrame(()=>{
      const all=items();if(!all.length)return;
      const trackLeft=track.getBoundingClientRect().left;
      let closest=0,distance=Infinity;
      all.forEach((item,index)=>{const current=Math.abs(item.getBoundingClientRect().left-trackLeft);if(current<distance){distance=current;closest=index}});
      page=Math.max(0,Math.min(pages-1,Math.round(closest/visible)));setButtons();
    });
  },{passive:true});
  track?.addEventListener('pointerdown',stopAutoplay);
  track?.addEventListener('pointerup',startAutoplay);
  carousel.addEventListener('mouseenter',stopAutoplay);
  carousel.addEventListener('mouseleave',startAutoplay);
  carousel.addEventListener('focusin',stopAutoplay);
  carousel.addEventListener('focusout',startAutoplay);
  mobile.addEventListener('change',()=>{refresh();startAutoplay()});
  new ResizeObserver(()=>refresh()).observe(track);
  refresh();startAutoplay();
});
