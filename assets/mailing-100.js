(() => {
  'use strict';

  const qs = (selector, root = document) => root.querySelector(selector);
  const qsa = (selector, root = document) => Array.from(root.querySelectorAll(selector));

  const updateAudience = () => {
    const type = qs('[data-bcs-audience-type]');
    if (!type) return;
    const yearWrap = qs('[data-bcs-audience-detail="registration_year"]');
    const campWrap = qs('[data-bcs-audience-detail="camp"]');
    const year = qs('[data-bcs-audience-year]');
    const camp = qs('[data-bcs-audience-camp]');
    const count = qs('[data-bcs-audience-count]');

    const selectedType = type.value;
    if (yearWrap) yearWrap.hidden = selectedType !== 'registration_year';
    if (campWrap) campWrap.hidden = selectedType !== 'camp';
    if (year) year.disabled = selectedType !== 'registration_year';
    if (camp) camp.disabled = selectedType !== 'camp';

    let currentCount = 0;
    if (selectedType === 'registration_year' && year) {
      currentCount = Number(year.selectedOptions[0]?.dataset.count || 0);
    } else if (selectedType === 'camp' && camp) {
      currentCount = Number(camp.selectedOptions[0]?.dataset.count || 0);
    } else {
      currentCount = Number(type.selectedOptions[0]?.dataset.count || 0);
    }
    if (count) count.textContent = new Intl.NumberFormat('pl-PL').format(currentCount);
  };

  const updateNewsletterTransport = () => {
    const transport = qs('[data-bcs-newsletter-transport]');
    const smtp = qs('[data-bcs-newsletter-smtp]');
    if (!transport || !smtp) return;
    smtp.hidden = transport.value !== 'smtp';
  };

  const setupDropzone = () => {
    const input = qs('#bcs-mailing-file-100');
    const zone = input?.closest('.bcs-mail-dropzone');
    if (!input || !zone) return;
    const strong = qs('strong', zone);
    input.addEventListener('change', () => {
      if (input.files?.[0] && strong) strong.textContent = input.files[0].name;
    });
    ['dragenter', 'dragover'].forEach(evt => zone.addEventListener(evt, () => zone.classList.add('is-drag')));
    ['dragleave', 'drop'].forEach(evt => zone.addEventListener(evt, () => zone.classList.remove('is-drag')));
  };

  document.addEventListener('change', (event) => {
    if (event.target.matches('[data-bcs-audience-type], [data-bcs-audience-year], [data-bcs-audience-camp]')) updateAudience();
    if (event.target.matches('[data-bcs-newsletter-transport]')) updateNewsletterTransport();
  });

  document.addEventListener('DOMContentLoaded', () => {
    updateAudience();
    updateNewsletterTransport();
    setupDropzone();
  });
})();
