(()=>{'use strict';
const stateNames={'acre':'AC','alagoas':'AL','amapá':'AP','amazonas':'AM','bahia':'BA','ceará':'CE','distrito federal':'DF','espírito santo':'ES','goiás':'GO','maranhão':'MA','mato grosso':'MT','mato grosso do sul':'MS','minas gerais':'MG','pará':'PA','paraíba':'PB','paraná':'PR','pernambuco':'PE','piauí':'PI','rio de janeiro':'RJ','rio grande do norte':'RN','rio grande do sul':'RS','rondônia':'RO','roraima':'RR','santa catarina':'SC','são paulo':'SP','sergipe':'SE','tocantins':'TO'};
const digits=value=>String(value||'').replace(/\D/g,'');
const setStatus=(form,text,kind='')=>{const target=form.querySelector('[data-address-status]');if(target){target.textContent=text;target.dataset.status=kind}};
const setValue=(form,name,value,force=false)=>{const field=form.querySelector('[name="'+name+'"]');if(field&&value&&(force||!field.value.trim()))field.value=value};
const fillZip=async(form)=>{
 const cepField=form.querySelector('[data-address-cep]');const cep=digits(cepField.value);
 if(cep.length!==8)return;
 cepField.value=cep.slice(0,5)+'-'+cep.slice(5);
 setStatus(form,'Consultando CEP...');
 try{
 const response=await fetch('https://viacep.com.br/ws/'+cep+'/json/',{headers:{Accept:'application/json'}});
 if(!response.ok)throw new Error('network');
 const data=await response.json();
 if(data.erro){setStatus(form,'CEP não encontrado. Confira o número ou preencha manualmente.','error');return}
 if(digits(cepField.value)!==cep)return;
 setValue(form,'street',data.logradouro,true);
 setValue(form,'neighborhood',data.bairro,true);
 setValue(form,'city',data.localidade,true);
 setValue(form,'state',data.uf,true);
 setValue(form,'city_ibge_code',data.ibge,true);
 setStatus(form,'Endereço sugerido pelo CEP. Confirme os dados e informe o número.','success');
 }catch{setStatus(form,'Não foi possível consultar o CEP. Você pode preencher manualmente.','error')}
};
const normalizeUF=(address)=>{
 const code=(address['ISO3166-2-lvl4']||address['ISO3166-2-lvl3']||'').split('-').pop().toUpperCase();
 if(/^[A-Z]{2}$/.test(code)&&code!=='BR')return code;
 return stateNames[(address.state||'').toLowerCase()]||'';
};
const locate=async(form,button)=>{
 if(!navigator.geolocation||!window.isSecureContext){setStatus(form,'A localização exige navegador compatível e conexão HTTPS. Preencha pelo CEP.','error');return}
 button.disabled=true;button.textContent='Localizando...';setStatus(form,'Aguardando autorização de localização...');
 try{
 const position=await new Promise((resolve,reject)=>navigator.geolocation.getCurrentPosition(resolve,reject,{enableHighAccuracy:false,timeout:12000,maximumAge:120000}));
 setStatus(form,'Consultando endereço aproximado...');
 const {latitude,longitude}=position.coords;
 // Solicitado apenas ao clicar. Não armazenamos nem enviamos coordenadas ao servidor Tuffer.
 const endpoint='https://nominatim.openstreetmap.org/reverse?format=jsonv2&addressdetails=1&zoom=18&accept-language=pt-BR&lat='+encodeURIComponent(latitude)+'&lon='+encodeURIComponent(longitude);
 const response=await fetch(endpoint,{headers:{Accept:'application/json'}});
 if(!response.ok)throw new Error('reverse');
 const json=await response.json();const a=json.address||{};
 if(a.country_code!=='br'){setStatus(form,'Localização fora do Brasil. Informe um endereço brasileiro manualmente.','error');return}
 setValue(form,'postal_code',a.postcode);
 setValue(form,'street',a.road||a.pedestrian||a.residential);
 setValue(form,'neighborhood',a.suburb||a.neighbourhood||a.city_district);
 setValue(form,'city',a.city||a.town||a.village||a.municipality);
 setValue(form,'state',normalizeUF(a));
 setValue(form,'number',a.house_number);
 const cep=form.querySelector('[data-address-cep]');
 if(digits(cep.value).length===8){cep.value=digits(cep.value).replace(/^(\d{5})(\d{3})$/,'$1-$2');await fillZip(form)}
 setStatus(form,'Localização aproximada (© OpenStreetMap). Confira CEP, rua e número antes de salvar.','success');
 }catch(error){
 const denied=error&&error.code===1;
 setStatus(form,denied?'Permissão negada. Você pode informar seu CEP e preencher manualmente.':'Não foi possível obter o endereço pela localização. Preencha o CEP manualmente.','error');
 }finally{button.disabled=false;button.textContent='Usar minha localização'}
};
document.querySelectorAll('[data-address-editor]').forEach(form=>{
 const cep=form.querySelector('[data-address-cep]');
 cep?.addEventListener('input',()=>{const raw=digits(cep.value).slice(0,8);cep.value=raw.length>5?raw.slice(0,5)+'-'+raw.slice(5):raw});
 cep?.addEventListener('blur',()=>fillZip(form));
 form.querySelector('[data-address-location]')?.addEventListener('click',event=>locate(form,event.currentTarget));
 form.addEventListener('submit',event=>{
 const postal=digits(cep.value);
 if(postal.length!==8){event.preventDefault();cep.setCustomValidity('Informe um CEP com 8 dígitos.');cep.reportValidity();cep.addEventListener('input',()=>cep.setCustomValidity(''),{once:true});return}
 const state=form.querySelector('[name="state"]');state.value=state.value.trim().toUpperCase();
 if(!/^[A-Z]{2}$/.test(state.value)){event.preventDefault();state.setCustomValidity('Informe a UF com duas letras.');state.reportValidity();state.addEventListener('input',()=>state.setCustomValidity(''),{once:true});return}
 form.querySelector('[type="submit"]').disabled=true;
 });
});
const modal=document.querySelector('[data-address-modal]');
if(!modal)return;
const open=()=>{if(!modal.open)modal.showModal()};
const close=()=>{if(modal.open)modal.close()};
document.querySelectorAll('[data-address-modal-open]').forEach(button=>button.addEventListener('click',event=>{event.preventDefault();open()}));
modal.querySelectorAll('[data-address-modal-close]').forEach(button=>button.addEventListener('click',close));
modal.addEventListener('click',event=>{if(event.target===modal)close()});
if(modal.hasAttribute('data-address-auto-open')||new URLSearchParams(location.search).get('novo')==='1')open();
})();
