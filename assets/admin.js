document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.bcs-inline-delete, .bcs-cancel-action').forEach(function (form) {
    form.addEventListener('submit', function (event) {
      var message = form.getAttribute('data-confirm') || 'Czy na pewno wykonać tę operację?';
      if (!window.confirm(message)) event.preventDefault();
    });
  });

  var master = document.querySelector('[data-bcs-check-all]');
  if (master) {
    master.addEventListener('change', function () {
      document.querySelectorAll('.bcs-reg-check').forEach(function (item) {
        item.checked = master.checked;
      });
    });
  }

  var providerSelect = document.getElementById('bcs-sms-provider');
  function refreshSmsProviderBoxes() {
    if (!providerSelect) return;
    document.querySelectorAll('[data-bcs-provider-box]').forEach(function (box) {
      var active = box.getAttribute('data-bcs-provider-box') === providerSelect.value;
      box.classList.toggle('is-active', active);
      box.setAttribute('aria-hidden', active ? 'false' : 'true');
    });
  }
  if (providerSelect) {
    providerSelect.addEventListener('change', refreshSmsProviderBoxes);
    refreshSmsProviderBoxes();
  }
});

document.addEventListener('DOMContentLoaded', function () {
  var mailSelect = document.getElementById('bcs-mail-transport');
  function refreshMailTransportBoxes() {
    document.querySelectorAll('[data-bcs-mail-box]').forEach(function (box) {
      var active = mailSelect && mailSelect.value === 'smtp';
      box.classList.toggle('is-active', active);
      box.setAttribute('aria-hidden', active ? 'false' : 'true');
    });
  }
  if (mailSelect) {
    mailSelect.addEventListener('change', refreshMailTransportBoxes);
    refreshMailTransportBoxes();
  }
});

// Automatyczne dopasowanie wysokości podglądu wiadomości HTML w module Poczta.
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.bcs-mail-preview-frame').forEach(function (frame) {
    frame.addEventListener('load', function () {
      try {
        var doc = frame.contentDocument || frame.contentWindow.document;
        if (!doc || !doc.documentElement) return;
        var height = Math.max(doc.body ? doc.body.scrollHeight : 0, doc.documentElement.scrollHeight || 0);
        if (height > 0) frame.style.height = Math.min(Math.max(height + 24, 420), 1400) + 'px';
        doc.querySelectorAll('a').forEach(function (link) {
          link.setAttribute('target', '_blank');
          link.setAttribute('rel', 'noopener noreferrer');
        });
      } catch (e) {}
    });
  });
});

// CRM: wyszukiwanie, filtrowanie i sortowanie bez przeładowania strony.
document.addEventListener('DOMContentLoaded', function () {
  var toolbar = document.querySelector('[data-bcs-live-filter]');
  var table = document.querySelector('[data-bcs-live-table]');
  if (!toolbar || !table) return;

  var tbody = table.querySelector('tbody');
  var search = toolbar.querySelector('[data-bcs-filter-search]');
  var camp = toolbar.querySelector('[data-bcs-filter-camp]');
  var status = toolbar.querySelector('[data-bcs-filter-status]');
  var reset = toolbar.querySelector('[data-bcs-filter-reset]');
  var resultCount = toolbar.querySelector('[data-bcs-results-count]');
  var emptyRow = tbody.querySelector('.bcs-live-empty');

  function rows() {
    return Array.prototype.slice.call(tbody.querySelectorAll('tr[data-id]'));
  }

  function normalize(value) {
    return String(value || '').toLocaleLowerCase('pl-PL').normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim();
  }

  function applyFilters() {
    var phrase = normalize(search ? search.value : '');
    var campId = camp ? String(camp.value || '0') : '0';
    var statusValue = status ? String(status.value || '') : '';
    var visible = 0;

    rows().forEach(function (row) {
      var haystack = normalize(row.getAttribute('data-search'));
      var matches = (!phrase || haystack.indexOf(phrase) !== -1)
        && (campId === '0' || row.getAttribute('data-camp-id') === campId)
        && (!statusValue || row.getAttribute('data-status') === statusValue);
      row.hidden = !matches;
      if (matches) visible += 1;
    });

    if (emptyRow) emptyRow.hidden = visible !== 0;
    if (resultCount) resultCount.textContent = 'Widoczne: ' + visible;
  }

  function valueFor(row, key) {
    var raw = row.getAttribute('data-' + key) || '';
    if (key === 'id' || key === 'paid' || key === 'requires') return Number(raw) || 0;
    if (key === 'updated' || key === 'created') return Date.parse(raw.replace(' ', 'T')) || 0;
    return normalize(raw);
  }

  function sortRows(key, direction) {
    var multiplier = direction === 'desc' ? -1 : 1;
    var sorted = rows().sort(function (a, b) {
      var av = valueFor(a, key);
      var bv = valueFor(b, key);
      if (typeof av === 'number' && typeof bv === 'number') return (av - bv) * multiplier;
      return String(av).localeCompare(String(bv), 'pl', { numeric: true, sensitivity: 'base' }) * multiplier;
    });
    sorted.forEach(function (row) { tbody.insertBefore(row, emptyRow || null); });
  }

  toolbar.addEventListener('submit', function (event) {
    event.preventDefault();
    applyFilters();
  });
  if (search) search.addEventListener('input', applyFilters);
  if (camp) camp.addEventListener('change', applyFilters);
  if (status) status.addEventListener('change', applyFilters);
  if (reset) reset.addEventListener('click', function () {
    if (search) search.value = '';
    if (camp) camp.value = '0';
    if (status) status.value = '';
    applyFilters();
  });

  table.querySelectorAll('[data-bcs-sort]').forEach(function (button) {
    button.addEventListener('click', function () {
      var key = button.getAttribute('data-bcs-sort');
      var current = button.getAttribute('data-direction') || 'asc';
      var next = current === 'asc' ? 'desc' : 'asc';
      table.querySelectorAll('[data-bcs-sort]').forEach(function (other) {
        other.classList.remove('is-sorted');
        other.removeAttribute('aria-sort');
      });
      button.setAttribute('data-direction', next);
      button.setAttribute('aria-sort', next === 'asc' ? 'ascending' : 'descending');
      button.classList.add('is-sorted');
      var arrow = button.querySelector('span');
      if (arrow) arrow.textContent = next === 'asc' ? '↑' : '↓';
      sortRows(key, next);
      applyFilters();
    });
  });

  applyFilters();
});

// Faktury: podgląd PDF w popupie i potwierdzenie usunięcia.
document.addEventListener('click', function (event) {
    const preview = event.target.closest('.bcs-invoice-preview');
    if (preview) {
        const modal = document.getElementById('bcs-invoice-modal');
        if (!modal) return;
        const frame = modal.querySelector('iframe');
        frame.src = preview.dataset.url || '';
        modal.hidden = false;
        document.body.style.overflow = 'hidden';
        return;
    }
    if (event.target.closest('.bcs-invoice-modal__close') || event.target.id === 'bcs-invoice-modal') {
        const modal = document.getElementById('bcs-invoice-modal');
        if (!modal) return;
        modal.hidden = true;
        modal.querySelector('iframe').src = 'about:blank';
        document.body.style.overflow = '';
    }
});
document.addEventListener('submit', function (event) {
    if (!event.target.matches('.bcs-invoice-delete-form')) return;
    if (!window.confirm('Czy na pewno usunąć fakturę? Usunięty zostanie również plik PDF.')) event.preventDefault();
});

// 0.12.1 — szybka wiadomość e-mail z kolumny Kontakt.
document.addEventListener('click', function (event) {
  var trigger = event.target.closest('.bcs-contact-email');
  var modal = document.getElementById('bcs-contact-modal');
  if (trigger && modal) {
    var form = modal.querySelector('form');
    form.querySelector('[name="registration_id"]').value = trigger.getAttribute('data-registration-id') || '';
    form.querySelector('[name="_wpnonce"]').value = trigger.getAttribute('data-nonce') || '';
    var recipient = modal.querySelector('[data-bcs-contact-recipient]');
    if (recipient) recipient.textContent = (trigger.getAttribute('data-client') || '') + ' — ' + (trigger.getAttribute('data-email') || '');
    modal.hidden = false;
    document.body.style.overflow = 'hidden';
    var subject = form.querySelector('[name="subject"]');
    if (subject) subject.focus();
    return;
  }
  if (modal && (event.target.closest('.bcs-contact-modal__close') || event.target === modal)) {
    modal.hidden = true;
    document.body.style.overflow = '';
  }
});
document.addEventListener('keydown', function (event) {
  if (event.key !== 'Escape') return;
  var modal = document.getElementById('bcs-contact-modal');
  if (modal && !modal.hidden) { modal.hidden = true; document.body.style.overflow = ''; }
});


// 0.12.5 — szczegóły logu w ustandaryzowanym popupie.
document.addEventListener('click', function (event) {
  var trigger = event.target.closest('.bcs-log-details');
  var modal = document.getElementById('bcs-log-modal');
  if (trigger && modal) {
    var title = modal.querySelector('#bcs-log-modal-title');
    var data = modal.querySelector('.bcs-log-data');
    if (title) title.textContent = trigger.getAttribute('data-title') || 'Szczegóły zdarzenia';
    if (data) data.textContent = trigger.getAttribute('data-details') || '{}';
    modal.hidden = false;
    document.body.style.overflow = 'hidden';
    return;
  }
  if (modal && (event.target.closest('.bcs-log-modal__close') || event.target === modal)) {
    modal.hidden = true;
    document.body.style.overflow = '';
  }
});
document.addEventListener('keydown', function (event) {
  if (event.key !== 'Escape') return;
  var modal = document.getElementById('bcs-log-modal');
  if (modal && !modal.hidden) { modal.hidden = true; document.body.style.overflow = ''; }
});

(function(){document.addEventListener('click',function(e){var b=e.target.closest('.bcs-registration-preview');if(b){var m=document.getElementById('bcs-registration-preview-modal'),c=m&&m.querySelector('[data-bcs-registration-preview-content]'),t=document.getElementById(b.dataset.previewTemplate||'');if(!m||!c||!t)return;c.replaceChildren(t.content.cloneNode(true));m.hidden=false;document.body.classList.add('bcs-modal-open');}if(e.target.closest('.bcs-registration-preview-close')||e.target.id==='bcs-registration-preview-modal'){var m=document.getElementById('bcs-registration-preview-modal');if(m)m.hidden=true;document.body.classList.remove('bcs-modal-open');}});document.addEventListener('keydown',function(e){if(e.key==='Escape'){var m=document.getElementById('bcs-registration-preview-modal');if(m)m.hidden=true;document.body.classList.remove('bcs-modal-open');}});})();

(function(){document.addEventListener('change',function(e){if(!e.target.matches('form input[name="start_date"]'))return;var form=e.target.closest('form');var end=form&&form.querySelector('input[name="end_date"]');if(end)end.value=e.target.value;});})();

/* BCS 0.15.3: unified data and e-mail preview modals. */
document.addEventListener('click', function (event) {
    var mailButton = event.target.closest('.bcs-mail-preview');
    if (mailButton) {
        var modal = document.getElementById('bcs-mail-preview-modal');
        var item = mailButton.closest('.bcs-mail-thread-item');
        var template = item ? item.querySelector('.bcs-mail-preview-template') : null;
        if (modal && template) {
            modal.querySelector('[data-bcs-mail-preview-title]').textContent = mailButton.dataset.title || 'Wiadomość e-mail';
            modal.querySelector('[data-bcs-mail-preview-content]').innerHTML = template.innerHTML;
            modal.hidden = false;
            document.body.classList.add('bcs-modal-open');
        }
        return;
    }
    if (event.target.closest('.bcs-mail-preview-close') || (event.target.id === 'bcs-mail-preview-modal')) {
        var mailModal = document.getElementById('bcs-mail-preview-modal');
        if (mailModal) mailModal.hidden = true;
        document.body.classList.remove('bcs-modal-open');
        return;
    }
    var dataButton = event.target.closest('.bcs-data-preview');
    if (dataButton) {
        var dataModal = document.getElementById('bcs-data-preview-modal');
        if (dataModal) {
            dataModal.querySelector('[data-bcs-data-preview-title]').textContent = dataButton.dataset.title || 'Podgląd';
            dataModal.querySelector('[data-bcs-data-preview-content]').textContent = dataButton.dataset.content || '—';
            dataModal.hidden = false;
            document.body.classList.add('bcs-modal-open');
        }
        return;
    }
    if (event.target.closest('.bcs-data-preview-close') || (event.target.id === 'bcs-data-preview-modal')) {
        var previewModal = document.getElementById('bcs-data-preview-modal');
        if (previewModal) previewModal.hidden = true;
        document.body.classList.remove('bcs-modal-open');
    }
});
document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape') return;
    ['bcs-mail-preview-modal', 'bcs-data-preview-modal'].forEach(function (id) {
        var modal = document.getElementById(id);
        if (modal) modal.hidden = true;
    });
    document.body.classList.remove('bcs-modal-open');
});

// 0.17.0 — moduł Feedback: przycisk w nagłówku, modal i zapis AJAX.
document.addEventListener('DOMContentLoaded', function () {
  var tools = document.querySelector('[data-bcs-feedback-tools]');
  var modal = document.querySelector('[data-bcs-feedback-modal]');
  if (!tools || !modal || typeof BCSFeedback === 'undefined') return;

  var pageHead = document.querySelector('.bcs-admin .bcs-page-head');
  if (pageHead) {
    var right = pageHead.querySelector('.bcs-actions');
    if (right) right.appendChild(tools);
    else pageHead.appendChild(tools);
  } else {
    var wrap = document.querySelector('.wrap.bcs-admin');
    var heading = wrap ? wrap.querySelector('h1') : null;
    if (heading) heading.insertAdjacentElement('afterend', tools);
  }

  var form = modal.querySelector('[data-bcs-feedback-form]');
  var textarea = modal.querySelector('textarea[name="description"]');
  var message = modal.querySelector('[data-bcs-feedback-message]');
  var submit = form ? form.querySelector('button[type="submit"]') : null;

  function openModal() {
    modal.hidden = false;
    document.body.classList.add('bcs-feedback-modal-open');
    message.textContent = '';
    message.className = 'bcs-feedback-message';
    window.setTimeout(function () { if (textarea) textarea.focus(); }, 50);
  }

  function closeModal() {
    modal.hidden = true;
    document.body.classList.remove('bcs-feedback-modal-open');
  }

  document.querySelectorAll('[data-bcs-feedback-open]').forEach(function (button) {
    button.addEventListener('click', openModal);
  });
  modal.querySelectorAll('[data-bcs-feedback-close]').forEach(function (button) {
    button.addEventListener('click', closeModal);
  });
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && !modal.hidden) closeModal();
  });

  if (!form) return;
  form.addEventListener('submit', function (event) {
    event.preventDefault();
    var description = textarea ? textarea.value.trim() : '';
    if (!description) {
      message.textContent = BCSFeedback.messages.required;
      message.className = 'bcs-feedback-message is-error';
      if (textarea) textarea.focus();
      return;
    }

    var data = new FormData();
    data.append('action', 'bcs_feedback_create');
    data.append('nonce', BCSFeedback.nonce);
    data.append('type', form.querySelector('[name="type"]').value);
    data.append('description', description);
    data.append('module', BCSFeedback.module);
    data.append('page_url', BCSFeedback.pageUrl);

    submit.disabled = true;
    submit.textContent = 'Zapisywanie…';
    message.textContent = '';
    fetch(BCSFeedback.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data })
      .then(function (response) { return response.json(); })
      .then(function (response) {
        if (!response.success) throw new Error(response.data && response.data.message ? response.data.message : BCSFeedback.messages.error);
        message.textContent = response.data.message || BCSFeedback.messages.saved;
        message.className = 'bcs-feedback-message is-success';
        form.reset();
        window.setTimeout(closeModal, 900);
      })
      .catch(function (error) {
        message.textContent = error.message || BCSFeedback.messages.error;
        message.className = 'bcs-feedback-message is-error';
      })
      .finally(function () {
        submit.disabled = false;
        submit.textContent = 'Wyślij zgłoszenie';
      });
  });
});
