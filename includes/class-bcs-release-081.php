<?php
if (!defined('ABSPATH')) exit;

/**
 * Wersja 0.81 – domknięcie UX weryfikacji formularza, poprawne daty CRM
 * oraz czytelne objaśnienie klucza szyfrującego KSeF.
 */
final class BCS_Release_081 {
    public static function init(): void {
        add_action('admin_footer', [__CLASS__, 'admin_fixes'], 9999);
    }

    public static function admin_fixes(): void {
        if (!current_user_can('manage_options')) return;
        $page = sanitize_key(wp_unslash($_GET['page'] ?? ''));
        if (!in_array($page, ['bcs-registrations', 'bcs-organizers'], true)) return;
        ?>
        <style>
        .bcs-ksef-master-key-help-081{margin:10px 0 0;padding:11px 13px;border-radius:8px;background:rgba(255,255,255,.72);color:#14532d;line-height:1.5}
        .bcs-ksef-master-key-help-081 code{white-space:nowrap}
        </style>
        <script>
        (() => {
            const page = <?php echo wp_json_encode($page); ?>;

            function closeCampFormPopups() {
                const selectors = [
                    '.bcs-contact-modal',
                    '.bcs-payment-modal-02024',
                    '[data-bcs-feedback-modal]',
                    '#bcs-invoice-modal',
                    '#bcs-log-modal',
                    '#bcs-mail-preview-modal',
                    '#bcs-data-preview-modal',
                    '#bcs-registration-preview-modal'
                ];
                document.querySelectorAll(selectors.join(',')).forEach((modal) => {
                    if (modal.id === 'bcs-result-popup-0190') return;
                    modal.hidden = true;
                    modal.classList.remove('show', 'is-open', 'open');
                    modal.setAttribute('aria-hidden', 'true');
                    const frame = modal.querySelector('iframe');
                    if (frame) frame.src = 'about:blank';
                });
                document.querySelectorAll('dialog[open]').forEach((dialog) => {
                    try { dialog.close(); } catch (error) { dialog.removeAttribute('open'); }
                });
                document.body.classList.remove('bcs-modal-open', 'bcs-feedback-modal-open');
                document.body.style.overflow = '';
            }

            function isCampFormSuccess(message, ok) {
                if (!ok) return false;
                const text = String(message || '').toLocaleLowerCase('pl-PL');
                return text.includes('formularz') && (
                    text.includes('zaakcept') ||
                    text.includes('potwierdz') ||
                    text.includes('zweryfik')
                );
            }

            function wrapResultPopup() {
                const original = window.bcsPopup0190;
                if (typeof original !== 'function' || original.__bcs081Wrapped) return;
                const wrapped = function(message, ok = true) {
                    if (isCampFormSuccess(message, ok)) closeCampFormPopups();
                    return original.call(this, message, ok);
                };
                wrapped.__bcs081Wrapped = true;
                window.bcsPopup0190 = wrapped;
            }

            function fixRegistrationDates() {
                if (page !== 'bcs-registrations') return;
                document.querySelectorAll('tr[data-id][data-created]').forEach((row) => {
                    if (row.dataset.bcsDateFixed === '081') return;
                    const raw = String(row.dataset.created || '').trim();
                    const match = raw.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::\d{2})?$/);
                    if (!match || !row.cells || !row.cells[1]) return;
                    row.cells[1].innerHTML = '<strong>' + match[3] + '.' + match[2] + '.' + match[1] + '</strong><br><small>' + match[4] + ':' + match[5] + '</small>';
                    row.dataset.bcsDateFixed = '081';
                });
            }

            function explainKsefMasterKey() {
                if (page !== 'bcs-organizers') return;
                const box = document.querySelector('.bcs-ksef-security-072');
                if (!box || box.querySelector('.bcs-ksef-master-key-help-081')) return;
                const status = box.querySelector(':scope > strong');
                if (status && box.classList.contains('is-ok')) {
                    status.textContent = '✓ Główny klucz szyfrujący tej instalacji jest dostępny.';
                }
                const help = document.createElement('div');
                help.className = 'bcs-ksef-master-key-help-081';
                help.innerHTML = '<strong>Jak to działa?</strong><br>Jeden <code>BCS_KSEF_SECRET_KEY</code> zabezpiecza tokeny wszystkich Organizatorów w tej instalacji WordPressa. Każdy Organizator ma własny token KSeF zapisany osobno w bazie i zaszyfrowany z własnym losowym nonce. Sam klucz główny nie jest zapisywany w bazie danych. Ustaw go raz jako zmienną środowiskową serwera albo w <code>wp-config.php</code> i nie zapisuj go w repozytorium. Nie potrzeba osobnego klucza szyfrującego dla każdego Organizatora.';
                box.appendChild(help);
            }

            function showRedirectSuccessIfNeeded() {
                if (page !== 'bcs-registrations') return;
                const params = new URLSearchParams(window.location.search);
                if (params.get('form_verified_action') !== '1') return;
                window.setTimeout(() => {
                    closeCampFormPopups();
                    if (typeof window.bcsPopup0190 === 'function') {
                        window.bcsPopup0190('Formularz obozowy został pomyślnie potwierdzony przez Organizatora.', true);
                    }
                }, 30);
            }

            function run() {
                wrapResultPopup();
                fixRegistrationDates();
                explainKsefMasterKey();
            }

            if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run);
            else run();
            window.setTimeout(run, 50);
            window.setTimeout(run, 250);
            showRedirectSuccessIfNeeded();
        })();
        </script>
        <?php
    }
}
