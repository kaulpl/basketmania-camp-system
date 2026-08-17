(()=>{
    'use strict';

    const data=window.BCSMailReply090||{};
    const messages=Array.isArray(data.messages)?data.messages:[];
    if(!messages.length)return;

    const correspondence=[...document.querySelectorAll('.bcs-accordion-panel')]
        .find(panel=>/Korespondencja e-mail/i.test(panel.querySelector('summary strong')?.textContent||''));
    if(!correspondence)return;

    if(!correspondence.id)correspondence.id='bcs-mail-correspondence';

    const inbound=[...correspondence.querySelectorAll('.bcs-mail-thread-item.is-inbound')];
    inbound.forEach((article,index)=>{
        const message=messages[index];
        if(!message)return;
        article.dataset.bcsReplyMessageId=String(message.id||'');
    });

    const previewModal=document.getElementById('bcs-mail-preview-modal');
    const previewDialog=previewModal?.querySelector('.bcs-mail-preview-dialog');
    const replyModal=document.getElementById('bcs-mail-reply-modal-090');
    const replyForm=replyModal?.querySelector('.bcs-mail-reply-form-090');
    if(!previewModal||!previewDialog||!replyModal||!replyForm)return;

    let currentMessage=null;

    let previewActions=previewDialog.querySelector('.bcs-mail-preview-reply-actions-090');
    if(!previewActions){
        previewActions=document.createElement('div');
        previewActions.className='bcs-mail-preview-reply-actions-090';
        const replyButton=document.createElement('button');
        replyButton.type='button';
        replyButton.className='button button-primary bcs-mail-reply-open-090';
        replyButton.innerHTML='<span class="dashicons dashicons-undo"></span> Odpowiedz';
        previewActions.appendChild(replyButton);
        const previewContent=previewDialog.querySelector('[data-bcs-mail-preview-content]');
        (previewContent||previewDialog).insertAdjacentElement('afterend',previewActions);
    }
    previewActions.hidden=true;

    const byId=new Map(messages.map(item=>[String(item.id),item]));
    const findMessageForArticle=article=>{
        if(!article)return null;
        const id=article.dataset.bcsReplyMessageId||'';
        return byId.get(id)||null;
    };

    const closeReply=()=>{
        replyModal.hidden=true;
        document.body.classList.remove('bcs-modal-open');
    };

    const openReply=message=>{
        if(!message)return;
        currentMessage=message;
        replyForm.elements.message_id.value=String(message.id||'');
        replyForm.elements._wpnonce.value=String(message.nonce||'');
        replyForm.elements.subject.value=String(message.replySubject||'');
        replyForm.elements.body.value='';
        const recipient=replyForm.querySelector('[data-bcs-mail-reply-recipient-090]');
        const context=replyForm.querySelector('[data-bcs-mail-reply-context-090]');
        if(recipient)recipient.textContent=(message.name?message.name+' · ':'')+message.email;
        if(context)context.textContent=(message.subject||'(bez tematu)')+(message.preview?' — '+message.preview:'');
        previewModal.hidden=true;
        replyModal.hidden=false;
        document.body.classList.add('bcs-modal-open');
        window.setTimeout(()=>replyForm.elements.body?.focus(),40);
    };

    document.addEventListener('click',event=>{
        const previewButton=event.target.closest('.bcs-mail-preview');
        if(previewButton&&correspondence.contains(previewButton)){
            const article=previewButton.closest('.bcs-mail-thread-item');
            currentMessage=article?.classList.contains('is-inbound')?findMessageForArticle(article):null;
            previewActions.hidden=!currentMessage;
            return;
        }

        const openButton=event.target.closest('.bcs-mail-reply-open-090');
        if(openButton){
            event.preventDefault();
            openReply(currentMessage);
            return;
        }

        if(event.target.closest('.bcs-mail-reply-close-090')||event.target.closest('.bcs-mail-reply-cancel-090')){
            event.preventDefault();
            closeReply();
            return;
        }

        if(event.target===replyModal)closeReply();
    },true);

    document.addEventListener('keydown',event=>{
        if(event.key==='Escape'&&!replyModal.hidden)closeReply();
    });

    if(new URLSearchParams(window.location.search).has('mail_reply_090')){
        const details=correspondence.querySelector('details');
        if(details)details.open=true;
        window.setTimeout(()=>correspondence.scrollIntoView({behavior:'smooth',block:'start'}),80);
    }
})();
