(() => {
  'use strict';

  let controller = null;

  const supported = () => Boolean(
    window.isSecureContext &&
    'OTPCredential' in window &&
    navigator.credentials &&
    typeof navigator.credentials.get === 'function'
  );

  const abort = () => {
    if (!controller) return;
    try { controller.abort(); } catch (error) {}
    controller = null;
  };

  const visible = (node) => Boolean(node && !node.hidden && node.getClientRects().length > 0);

  const waitVisible = (inputSelector, modalSelector, timeout = 8000) => new Promise((resolve) => {
    const started = Date.now();
    const tick = () => {
      const input = document.querySelector(inputSelector);
      const modal = modalSelector ? document.querySelector(modalSelector) : null;
      if (input && (!modalSelector || visible(modal))) {
        resolve(input);
        return;
      }
      if (Date.now() - started >= timeout) {
        resolve(input || null);
        return;
      }
      window.setTimeout(tick, 80);
    };
    tick();
  });

  const startWebOtp = async ({ input, modal, verify, form }) => {
    if (!supported()) return false;

    abort();
    controller = new AbortController();
    const localController = controller;
    window.setTimeout(() => {
      if (controller === localController) abort();
    }, 125000);

    try {
      const credential = await navigator.credentials.get({
        otp: { transport: ['sms'] },
        signal: localController.signal,
      });
      const code = String(credential?.code || '').replace(/\D/g, '').slice(0, 6);
      if (code.length !== 6) return false;

      const field = await waitVisible(input, modal, 8000);
      if (!field) return false;
      field.value = code;
      field.dispatchEvent(new Event('input', { bubbles: true }));
      field.dispatchEvent(new Event('change', { bubbles: true }));

      window.setTimeout(() => {
        if (form) {
          const formNode = document.querySelector(form);
          if (!formNode) return;
          if (typeof formNode.requestSubmit === 'function') formNode.requestSubmit();
          else formNode.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
          return;
        }
        if (verify) document.querySelector(verify)?.click();
      }, 120);
      return true;
    } catch (error) {
      if (error?.name !== 'AbortError') console.debug('Basketmania WebOTP:', error);
      return false;
    } finally {
      if (controller === localController) controller = null;
    }
  };

  const prepareParentField = () => {
    const input = document.querySelector('#bcs-code');
    if (!input) return;
    input.name = 'bcs_otp_code';
    input.type = 'text';
    input.maxLength = 6;
    input.setAttribute('inputmode', 'numeric');
    input.setAttribute('pattern', '[0-9]{6}');
    input.setAttribute('autocomplete', 'one-time-code');
    input.setAttribute('autocapitalize', 'off');
    input.setAttribute('spellcheck', 'false');
  };

  const prepareOrganizerField = () => {
    const input = document.querySelector('#bcs-org-otp-code-079');
    if (input) {
      input.name = 'bcs_organizer_otp_code';
      input.type = 'text';
      input.maxLength = 6;
      input.setAttribute('inputmode', 'numeric');
      input.setAttribute('pattern', '[0-9]{6}');
      input.setAttribute('autocomplete', 'one-time-code');
      input.setAttribute('autocapitalize', 'off');
      input.setAttribute('spellcheck', 'false');
    }

    const note = document.querySelector('.bcs-otp079-note');
    if (note && !note.dataset.bcs085) {
      note.dataset.bcs085 = '1';
      note.textContent = 'Safari na Macu może podpowiedzieć kod z iPhone’a. Chrome WebOTP działa na Androidzie oraz na komputerze z kodem przekazanym z Androida przez Chrome Sync; Chrome nie odbiera WebOTP z iPhone’a.';
    }
  };

  const prepareParentNote = () => {
    const modal = document.querySelector('#bcs-otp-modal .bcs-modal-dialog');
    if (!modal || modal.querySelector('.bcs-webotp-note-085')) return;
    const input = modal.querySelector('#bcs-code');
    if (!input) return;
    const note = document.createElement('small');
    note.className = 'bcs-webotp-note-085';
    note.textContent = supported()
      ? 'Chrome może pobrać kod z SMS automatycznie po Twoim potwierdzeniu.'
      : 'Automatyczne pobranie kodu zależy od przeglądarki i urządzenia; kod można zawsze wpisać ręcznie.';
    input.closest('label')?.insertAdjacentElement('afterend', note);
  };

  const prepare = () => {
    prepareParentField();
    prepareOrganizerField();
    prepareParentNote();
  };

  // Capture jest celowy: WebOTP rozpoczyna oczekiwanie zanim historyczne listenery
  // uruchomią AJAX, który wysyła wiadomość SMS.
  document.addEventListener('click', (event) => {
    if (event.target.closest('#bcs-send-code')) {
      prepareParentField();
      startWebOtp({
        input: '#bcs-code',
        modal: '#bcs-otp-modal',
        verify: '#bcs-verify-code',
      });
      return;
    }

    if (event.target.closest('.bcs-org-sign-046')) {
      startWebOtp({
        input: '#bcs-org-otp-code-079',
        modal: '#bcs-org-otp-modal-079',
        form: '#bcs-org-otp-form-079',
      });
      window.setTimeout(prepareOrganizerField, 100);
      window.setTimeout(prepareOrganizerField, 500);
    }
  }, true);

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', prepare, { once: true });
  else prepare();

  new MutationObserver(prepare).observe(document.documentElement, { childList: true, subtree: true });
})();
