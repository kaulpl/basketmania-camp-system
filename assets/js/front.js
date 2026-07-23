document.addEventListener('DOMContentLoaded', () => {
  const watchedForm=document.querySelector('.bcs-camp-form[data-bcs-lock-watch="1"]');
  if(watchedForm&&window.BCS&&BCS.ajax){
    let checking=false;
    const checkLock=async()=>{
      if(checking||!document.body.contains(watchedForm))return;
      checking=true;
      try{
        const body=new URLSearchParams({action:'bcs_parent_form_lock_status',registration_id:watchedForm.dataset.registrationId||'',token:watchedForm.dataset.token||''});
        const response=await fetch(BCS.ajax,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body});
        const json=await response.json();
        if(json.success&&(json.data.locked||json.data.verified)){
          const url=new URL(window.location.href);
          url.searchParams.delete('edit');
          if(json.data.locked)url.searchParams.set('edit_locked','1');
          window.location.replace(url.toString());
        }
      }catch(error){}
      finally{checking=false;}
    };
    window.setInterval(checkLock,5000);
  }
  const agreementModal = document.querySelector('#bcs-agreement-modal');
  const agreementFrame = document.querySelector('#bcs-agreement-frame');
  const openAgreement = (url) => {
    if (!agreementModal || !agreementFrame || !url) return;
    agreementFrame.src = url;
    agreementModal.hidden = false;
    document.body.classList.add('bcs-modal-open');
  };
  const closeAgreement = () => {
    if (!agreementModal || !agreementFrame) return;
    agreementModal.hidden = true;
    agreementFrame.src = 'about:blank';
    document.body.classList.remove('bcs-modal-open');
  };
  document.querySelectorAll('.bcs-open-agreement').forEach(button => {
    button.addEventListener('click', () => openAgreement(button.dataset.agreementUrl || ''));
  });
  document.querySelectorAll('[data-close-agreement]').forEach(el => el.addEventListener('click', closeAgreement));

  const root = document.querySelector('#bcs-otp');
  if (!root) {
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && agreementModal && !agreementModal.hidden) closeAgreement(); });
    return;
  }
  const message = document.querySelector('#bcs-message');
  const modalMessage = document.querySelector('#bcs-modal-message');
  const send = document.querySelector('#bcs-send-code');
  const verify = document.querySelector('#bcs-verify-code');
  const checked = document.querySelector('#bcs-declaration-check');
  const modal = document.querySelector('#bcs-otp-modal');
  const code = document.querySelector('#bcs-code');
  const countdown = document.querySelector('#bcs-otp-countdown');
  let timer = null;
  let expiresAt = 0;

  const post = async (action, extra = {}) => {
    const body = new URLSearchParams({action,nonce:BCS.nonce,agreement_id:root.dataset.agreement,token:root.dataset.token,agreement_read:checked?.checked?'1':'0',...extra});
    const res = await fetch(BCS.ajax,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body});
    return res.json();
  };
  const closeModal = () => { modal.hidden=true; document.body.classList.remove('bcs-modal-open'); };
  const openModal = () => { modal.hidden=false; document.body.classList.add('bcs-modal-open'); code.value=''; modalMessage.textContent=''; setTimeout(()=>code.focus(),50); };
  const tick = () => { const left=Math.max(0,Math.ceil((expiresAt-Date.now())/1000)); countdown.textContent=`${String(Math.floor(left/60)).padStart(2,'0')}:${String(left%60).padStart(2,'0')}`; if(left<=0){clearInterval(timer);verify.disabled=true;modalMessage.textContent='Kod wygasł. Zamknij okno i wyślij nowy kod.';} };
  const startTimer = (seconds) => { clearInterval(timer); expiresAt=Date.now()+(Math.max(1,Number(seconds)||Number(root.dataset.otpSeconds)||120)*1000); verify.disabled=false; tick(); timer=setInterval(tick,1000); };
  const gateResend=(seconds)=>{let left=Math.max(0,Number(seconds)||0);send.disabled=true;const original='Potwierdź podpis umowy SMS-em';const id=setInterval(()=>{left--;send.textContent=left>0?`Wyślij ponownie za ${String(Math.floor(left/60)).padStart(2,'0')}:${String(left%60).padStart(2,'0')}`:original;if(left<=0){clearInterval(id);send.disabled=!checked.checked;}},1000);};

  checked?.addEventListener('change',()=>{send.disabled=!checked.checked;});
  document.querySelectorAll('[data-close-otp]').forEach(el=>el.addEventListener('click',closeModal));
  document.addEventListener('keydown',e=>{if(e.key==='Escape'){if(modal&&!modal.hidden)closeModal();else if(agreementModal&&!agreementModal.hidden)closeAgreement();}});
  code?.addEventListener('input',()=>{code.value=code.value.replace(/\D/g,'').slice(0,6);});
  send?.addEventListener('click',async()=>{if(!checked.checked){message.textContent='Najpierw otwórz umowę i zaznacz potwierdzenie zapoznania się z jej treścią.';return;}send.disabled=true;message.textContent='Wysyłanie kodu SMS…';try{const r=await post('bcs_send_otp');message.textContent=r.data?.message||'Nie udało się wysłać kodu.';if(r.success){openModal();startTimer(r.data?.valid_seconds);gateResend(r.data?.retry_after||r.data?.valid_seconds);}else if(r.data?.retry_after){gateResend(r.data.retry_after);}}catch{message.textContent='Błąd połączenia podczas wysyłania kodu.';}send.disabled=!checked.checked;});
  verify?.addEventListener('click',async()=>{if(code.value.length!==6){modalMessage.textContent='Wpisz pełny 6-cyfrowy kod.';return;}verify.disabled=true;modalMessage.textContent='Weryfikacja podpisu…';try{const r=await post('bcs_verify_otp',{code:code.value,declaration:document.querySelector('#bcs-declaration').value});modalMessage.textContent=r.data?.message||'Nie udało się potwierdzić umowy.';if(r.success){clearInterval(timer);setTimeout(()=>location.reload(),900);return;}}catch{modalMessage.textContent='Błąd połączenia podczas weryfikacji.';}verify.disabled=false;});
});

;(()=>{document.querySelectorAll('.bcs-lock-countdown').forEach(el=>{let left=Number(el.dataset.seconds)||0;const tick=()=>{el.textContent=`${String(Math.floor(left/60)).padStart(2,'0')}:${String(left%60).padStart(2,'0')}`;if(left<=0){location.reload();return;}left--;setTimeout(tick,1000)};tick();});})();
