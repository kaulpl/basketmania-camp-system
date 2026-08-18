(function () {
    'use strict';

    function auditActionLabel(type, config) {
        if (!type || type.indexOf('audit_') !== 0) return '';
        var key = type.substring(6);
        if (config.actionLabels && config.actionLabels[key]) return config.actionLabels[key];
        if (key.indexOf('marketing') !== -1 || key.indexOf('mailing') !== -1 || key.indexOf('campaign') !== -1) return 'Wykonano działanie w module Mailing';
        if (key.indexOf('mail') !== -1) return 'Wykonano działanie w module Poczta';
        if (key.indexOf('invoice') !== -1 || key.indexOf('ksef') !== -1) return 'Wykonano działanie w module Faktury i KSeF';
        if (key.indexOf('agreement') !== -1 || key.indexOf('otp') !== -1) return 'Wykonano działanie w module Umowy';
        if (key.indexOf('payment') !== -1 || key.indexOf('stripe') !== -1 || key.indexOf('paid') !== -1) return 'Wykonano działanie w module Płatności';
        if (key.indexOf('registration') !== -1 || key.indexOf('crm') !== -1 || key.indexOf('workflow') !== -1 || key.indexOf('form') !== -1) return 'Wykonano działanie w obsłudze zgłoszeń';
        if (key.indexOf('camp') !== -1 || key.indexOf('report') !== -1 || key.indexOf('bracket') !== -1) return 'Wykonano działanie dotyczące turnusu lub raportu';
        return 'Wykonano działanie w systemie';
    }

    function translateHistory() {
        var config = window.BCSAudit107 || {};
        var known = config.eventLabels || {};
        var unknown = config.unknownLabel || 'Zdarzenie systemowe';
        document.querySelectorAll('.bcs-history-panel .bcs-timeline-item[data-event-type]').forEach(function (item) {
            var type = item.getAttribute('data-event-type') || '';
            if (type.indexOf('activity_') === 0) return;
            var label = known[type] || auditActionLabel(type, config);
            if (!label && type.indexOf('crm_') === 0) label = config.actionLabels && config.actionLabels[type] ? config.actionLabels[type] : 'Działanie w CRM';
            if (!label) label = unknown;
            var title = item.querySelector('div > strong');
            if (title) title.textContent = label;
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', translateHistory);
    } else {
        translateHistory();
    }
})();
