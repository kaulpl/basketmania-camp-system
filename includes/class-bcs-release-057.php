<?php
if (!defined('ABSPATH')) exit;

final class BCS_Release_057 {
    private const MIGRATION_OPTION = 'bcs_release_057_agreement_template_migrated';
    private const AJAX_ACTION = 'bcs_057_generate_invoice';

    public static function init(): void {
        add_action('admin_init', [__CLASS__, 'migrate_agreement_template'], 2);
        add_action('wp_ajax_'.self::AJAX_ACTION, [__CLASS__, 'ajax_generate_invoice']);
        add_action('admin_head', [__CLASS__, 'admin_invoice_script'], 1);
        add_action('admin_footer', [__CLASS__, 'admin_invoice_modal'], 99);
    }

    private static function normalized(string $text): string {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return mb_strtoupper((string)preg_replace('/\s+/u', ' ', trim($text)), 'UTF-8');
    }

    private static function load_fragment(string $html): ?DOMDocument {
        if (!class_exists('DOMDocument')) return null;
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $ok = $dom->loadHTML(
            '<?xml encoding="utf-8" ?><div id="bcs-057-root">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $ok ? $dom : null;
    }

    private static function inner_html(DOMNode $node): string {
        $html = '';
        foreach ($node->childNodes as $child) $html .= $node->ownerDocument->saveHTML($child);
        return $html;
    }

    private static function add_class(DOMElement $element, string $class): void {
        $classes = preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [];
        if (!in_array($class, $classes, true)) $classes[] = $class;
        $element->setAttribute('class', trim(implode(' ', array_filter($classes))));
    }

    private static function append_style(DOMElement $element, string $declaration): void {
        $style = trim($element->getAttribute('style'));
        if ($style !== '' && !str_ends_with($style, ';')) $style .= ';';
        $element->setAttribute('style', $style.$declaration);
    }

    private static function next_element_sibling(DOMNode $node): ?DOMElement {
        $next = $node->nextSibling;
        while ($next && !$next instanceof DOMElement) $next = $next->nextSibling;
        return $next instanceof DOMElement ? $next : null;
    }

    private static function is_attachment_heading(string $text): bool {
        return (str_contains($text, 'ZAŁĄCZNIK NR 1') || str_contains($text, 'ZALACZNIK NR 1'))
            && str_contains($text, 'KARTA KWALIFIKACYJNA UCZESTNIKA WYPOCZYNKU');
    }

    private static function is_educator_heading(string $text): bool {
        return str_contains($text, 'VI.') && str_contains($text, 'INFORMACJA I SPOSTRZEŻENIA WYCHOWAWCY');
    }

    private static function is_proof_heading(string $text): bool {
        return str_contains($text, 'SEKCJA DOWODOWA ZAWARCIA UMOWY')
            || str_contains($text, 'POTWIERDZENIE ZAWARCIA UMOWY');
    }

    private static function dotted_line(): string {
        return '....................................................................................................................................................';
    }

    public static function normalize_agreement_template(string $html): string {
        if (trim($html) === '') return $html;
        $dom = self::load_fragment($html);
        if (!$dom) {
            $html = preg_replace_callback(
                '~<h([1-3])\b([^>]*)>(.*?)</h\1>~isu',
                static function (array $match): string {
                    $text = self::normalized(wp_strip_all_tags($match[3]));
                    if (!self::is_attachment_heading($text)) return $match[0];
                    $content = $match[3];
                    if (!str_contains($text, 'BASKETMANIA CAMP')) $content .= '<br>BASKETMANIA CAMP';
                    $attrs = $match[2];
                    if (preg_match('/\sstyle=("|\')([^"\']*)\1/iu', $attrs, $style_match)) {
                        $style = rtrim(trim($style_match[2]), ';').';text-align:center;';
                        $attrs = preg_replace('/\sstyle=("|\')([^"\']*)\1/iu', ' style="'.esc_attr($style).'"', $attrs, 1);
                    } else {
                        $attrs .= ' style="text-align:center"';
                    }
                    return '<h'.$match[1].$attrs.'>'.$content.'</h'.$match[1].'>';
                },
                $html,
                1
            );
            $html = preg_replace(
                '~(<h3\b[^>]*>\s*VI\.\s*INFORMACJA I SPOSTRZEŻENIA WYCHOWAWCY\s*</h3>\s*)<p\b[^>]*>.*?</p>~isu',
                '$1<p>'.self::dotted_line().'<br>'.self::dotted_line().'</p>',
                (string)$html,
                1
            );
            return (string)$html;
        }

        $xpath = new DOMXPath($dom);
        foreach ($xpath->query('//h1|//h2|//h3') as $node) {
            if (!$node instanceof DOMElement) continue;
            $text = self::normalized($node->textContent ?? '');
            if (self::is_attachment_heading($text)) {
                self::add_class($node, 'bcs-attachment-heading-057');
                self::append_style($node, 'text-align:center;');
                if (!str_contains($text, 'BASKETMANIA CAMP')) {
                    $node->appendChild($dom->createElement('br'));
                    $node->appendChild($dom->createTextNode('BASKETMANIA CAMP'));
                }
            }
            if (self::is_educator_heading($text)) {
                $paragraph = self::next_element_sibling($node);
                if ($paragraph && strtolower($paragraph->tagName) === 'p') {
                    while ($paragraph->firstChild) $paragraph->removeChild($paragraph->firstChild);
                    $paragraph->appendChild($dom->createTextNode(self::dotted_line()));
                    $paragraph->appendChild($dom->createElement('br'));
                    $paragraph->appendChild($dom->createTextNode(self::dotted_line()));
                }
            }
        }

        $root = $dom->getElementById('bcs-057-root');
        return $root ? trim(self::inner_html($root)) : $html;
    }

    public static function prepare_agreement_html(string $html): string {
        if (trim($html) === '') return $html;
        $plain = self::normalized(wp_strip_all_tags($html));
        if (!str_contains($plain, 'UMOWA') && !str_contains($plain, 'KARTA KWALIFIKACYJNA')) return $html;

        $html = self::normalize_agreement_template($html);
        $dom = self::load_fragment($html);
        if (!$dom) {
            return '<style id="bcs-agreement-style-057">.bcs-attachment-heading-057{text-align:center!important}.proof,.bcs-proof-start-057{page-break-before:always!important;break-before:page!important;margin-top:0!important}</style>'.$html;
        }

        $xpath = new DOMXPath($dom);
        foreach ($xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " proof ")]') as $proof) {
            if (!$proof instanceof DOMElement) continue;
            self::add_class($proof, 'bcs-proof-start-057');
            self::append_style($proof, 'page-break-before:always;break-before:page;margin-top:0;');
        }
        foreach ($xpath->query('//h1|//h2|//h3') as $heading) {
            if (!$heading instanceof DOMElement) continue;
            if (!self::is_proof_heading(self::normalized($heading->textContent ?? ''))) continue;
            $container = $heading->parentNode instanceof DOMElement ? $heading->parentNode : $heading;
            self::add_class($container, 'bcs-proof-start-057');
            self::append_style($container, 'page-break-before:always;break-before:page;margin-top:0;');
        }

        if (!$xpath->query('//*[@id="bcs-agreement-style-057"]')->length) {
            $style = $dom->createElement('style');
            $style->setAttribute('id', 'bcs-agreement-style-057');
            $style->appendChild($dom->createTextNode(
                '.bcs-attachment-heading-057{text-align:center!important}'
                .'.proof,.bcs-proof-start-057{page-break-before:always!important;break-before:page!important;margin-top:0!important}'
            ));
            $head = $dom->getElementsByTagName('head')->item(0);
            if ($head) $head->appendChild($style);
            else {
                $root = $dom->getElementById('bcs-057-root');
                if ($root) $root->insertBefore($style, $root->firstChild);
            }
        }

        $root = $dom->getElementById('bcs-057-root');
        return $root ? trim(self::inner_html($root)) : $html;
    }

    public static function migrate_agreement_template(): void {
        if (get_option(self::MIGRATION_OPTION)) return;
        $saved = get_option('bcs_content_templates', []);
        if (!is_array($saved)) $saved = [];
        $current = (string)($saved['documents']['agreement'] ?? BCS_Agreements::default_template());
        $saved['documents']['agreement'] = self::normalize_agreement_template($current);
        update_option('bcs_content_templates', $saved, false);
        update_option(self::MIGRATION_OPTION, 1, false);
    }

    private static function invoice_row(int $registration_id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT i.*,r.invoice_status,r.parent_email FROM ".BCS_DB::table('invoices')." i
             JOIN ".BCS_DB::table('registrations')." r ON r.id=i.registration_id
             WHERE i.registration_id=%d ORDER BY i.id DESC LIMIT 1",
            $registration_id
        )) ?: null;
    }

    public static function ajax_generate_invoice(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message'=>'Brak uprawnień do generowania faktur.'], 403);
        }
        $registration_id = absint($_POST['registration_id'] ?? 0);
        $nonce = sanitize_text_field(wp_unslash($_POST['nonce'] ?? ''));
        if (!$registration_id || !wp_verify_nonce($nonce, 'bcs_crm_'.$registration_id)) {
            wp_send_json_error(['message'=>'Sesja wygasła. Odśwież stronę i spróbuj ponownie.'], 403);
        }

        $existing = self::invoice_row($registration_id);
        $created_now = false;
        if (!$existing) {
            if (!BCS_Workflow_Engine::invoice_available($registration_id)) {
                wp_send_json_error(['message'=>'Faktura nie jest jeszcze dostępna dla tego zgłoszenia. Sprawdź podpisanie umowy, płatność i datę turnusu.'], 409);
            }
            $ok = BCS_Workflow_Engine::generate_invoice($registration_id);
            $existing = self::invoice_row($registration_id);
            if (!$ok || !$existing) {
                wp_send_json_error(['message'=>'Nie udało się wygenerować faktury. Sprawdź logi systemowe i konfigurację PDF oraz poczty.'], 500);
            }
            $created_now = true;
            if (class_exists('BCS_CRM')) {
                BCS_CRM::activity($registration_id, 'invoice', 'Wygenerowano fakturę', (string)$existing->invoice_number);
            }
        }

        $sent = (string)$existing->status === 'sent' || (string)$existing->invoice_status === 'sent';
        $message = $created_now
            ? ($sent
                ? 'Faktura '.$existing->invoice_number.' została wygenerowana i wysłana do rodzica.'
                : 'Faktura '.$existing->invoice_number.' została wygenerowana. Wysyłka e-mail nie została potwierdzona — dokument pozostaje dostępny w systemie.')
            : 'Faktura '.$existing->invoice_number.' była już wygenerowana dla tego zgłoszenia.';

        wp_send_json_success([
            'message'=>$message,
            'level'=>$sent ? 'success' : 'warning',
            'registration_id'=>$registration_id,
            'invoice_number'=>(string)$existing->invoice_number,
            'invoice_status'=>(string)$existing->invoice_status,
            'delivery_status'=>(string)$existing->status,
            'download_url'=>BCS_Document_Engine::download_url($registration_id, 'invoice'),
        ]);
    }

    private static function is_registrations_page(): bool {
        return is_admin() && sanitize_key((string)($_GET['page'] ?? '')) === 'bcs-registrations';
    }

    public static function admin_invoice_script(): void {
        if (!self::is_registrations_page()) return;
        $config = [
            'ajaxUrl'=>admin_url('admin-ajax.php'),
            'action'=>self::AJAX_ACTION,
        ];
        ?>
        <style id="bcs-invoice-057-style">
            #bcs-invoice-result-modal-057 .bcs-invoice-result-icon-057{width:52px;height:52px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:28px;font-weight:800;background:#fff1df;color:#f57618}
            #bcs-invoice-result-modal-057.is-success .bcs-invoice-result-icon-057{background:#ecfdf3;color:#15803d}
            #bcs-invoice-result-modal-057.is-error .bcs-invoice-result-icon-057{background:#fef2f2;color:#b91c1c}
            #bcs-invoice-result-modal-057 .bcs-invoice-result-body-057{text-align:center;padding:8px 6px 2px}
            #bcs-invoice-result-modal-057 .bcs-invoice-result-body-057 h2{margin:0 0 10px}
            #bcs-invoice-result-modal-057 .bcs-invoice-result-body-057 p{font-size:15px;line-height:1.55;margin:0 0 18px}
            #bcs-invoice-result-modal-057 .bcs-invoice-result-actions-057{display:flex;gap:10px;justify-content:center;flex-wrap:wrap}
            .bcs-invoice-loading-057{opacity:.65;pointer-events:none}
        </style>
        <script id="bcs-invoice-057-script">
        (() => {
            'use strict';
            const cfg = <?php echo wp_json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
            let activeRequest = false;

            const modal = () => document.getElementById('bcs-invoice-result-modal-057');
            const showModal = (state, title, message, downloadUrl = '') => {
                const box = modal();
                if (!box) return;
                box.classList.remove('is-success', 'is-error', 'is-loading');
                box.classList.add(state === 'success' ? 'is-success' : state === 'error' ? 'is-error' : 'is-loading');
                box.querySelector('[data-bcs-invoice-icon-057]').textContent = state === 'success' ? '✓' : state === 'error' ? '!' : '…';
                box.querySelector('[data-bcs-invoice-title-057]').textContent = title;
                box.querySelector('[data-bcs-invoice-message-057]').textContent = message;
                const download = box.querySelector('[data-bcs-invoice-download-057]');
                if (downloadUrl) { download.href = downloadUrl; download.hidden = false; }
                else { download.hidden = true; download.removeAttribute('href'); }
                box.hidden = false;
                document.body.classList.add('bcs-modal-open');
            };
            const closeModal = () => {
                const box = modal();
                if (box) box.hidden = true;
                document.body.classList.remove('bcs-modal-open');
            };

            const invoiceAction = (form, submitter) => {
                const action = submitter?.value || form.querySelector('[name="bcs_crm_action"]')?.value || '';
                return action === 'invoice_generate';
            };

            const updateInterface = (data) => {
                const id = String(data.registration_id || '');
                const done = '<span class="bcs-action-done bcs-invoice-completed"><span class="dashicons dashicons-yes-alt"></span> Faktura '+String(data.invoice_number || '')+' — wygenerowana</span>';
                document.querySelectorAll('form').forEach((form) => {
                    const rid = form.querySelector('[name="registration_id"]')?.value || '';
                    const action = form.querySelector('[name="bcs_crm_action"][value="invoice_generate"]') || form.querySelector('button[name="bcs_crm_action"][value="invoice_generate"]');
                    if (rid === id && action) form.outerHTML = done;
                });
                const row = document.querySelector('tr[data-id="'+CSS.escape(id)+'"]');
                if (row) {
                    const actions = row.querySelector('[data-bcs-col="actions"]');
                    if (actions) actions.innerHTML = done;
                    const fv = row.querySelector('.milestone-invoice');
                    if (fv) { fv.classList.remove('is-pending'); fv.classList.add('is-done'); fv.title = 'Faktura – wykonano'; }
                    row.classList.add('bcs-registration-complete');
                    row.classList.remove('bcs-requires-action');
                    row.dataset.requires = '0';
                }
                const grid = document.querySelector('.bcs-crm-layout main .bcs-stat-grid');
                if (grid && !grid.querySelector('[data-bcs-invoice-summary-057]')) {
                    const item = document.createElement('div');
                    item.dataset.bcsInvoiceSummary057 = '1';
                    item.innerHTML = '<span>Faktura</span><strong class="bcs-summary-value"><span class="dashicons dashicons-yes-alt bcs-summary-check-icon" aria-label="Wykonano"></span> '+String(data.invoice_number || 'Wygenerowana')+' <a class="bcs-summary-download" href="'+String(data.download_url || '#')+'" title="Pobierz fakturę"><span class="dashicons dashicons-download"></span></a></strong>';
                    grid.appendChild(item);
                }
            };

            const run = (form, button) => {
                if (activeRequest) return;
                const id = form.querySelector('[name="registration_id"]')?.value || '';
                const nonce = form.querySelector('[name="_wpnonce"]')?.value || '';
                if (!id || !nonce) {
                    showModal('error', 'Nie udało się rozpocząć', 'Brakuje danych zgłoszenia albo sesja wygasła. Odśwież stronę.');
                    return;
                }
                activeRequest = true;
                if (button) { button.disabled = true; button.classList.add('bcs-invoice-loading-057'); button.dataset.originalText = button.textContent; button.textContent = 'Generowanie…'; }
                showModal('loading', 'Generowanie faktury', 'Trwa tworzenie dokumentu i aktualizacja statusów.');
                const data = new FormData();
                data.append('action', cfg.action);
                data.append('registration_id', id);
                data.append('nonce', nonce);
                fetch(cfg.ajaxUrl, {method:'POST', credentials:'same-origin', body:data})
                    .then(async (response) => {
                        const json = await response.json().catch(() => null);
                        if (!response.ok || !json || !json.success) throw new Error(json?.data?.message || 'Nie udało się wygenerować faktury.');
                        return json.data;
                    })
                    .then((result) => {
                        updateInterface(result);
                        showModal(result.level === 'warning' ? 'error' : 'success', result.level === 'warning' ? 'Faktura wygenerowana z uwagą' : 'Faktura wygenerowana', result.message, result.download_url || '');
                    })
                    .catch((error) => showModal('error', 'Generowanie faktury nie powiodło się', error.message || 'Wystąpił nieznany błąd.'))
                    .finally(() => {
                        activeRequest = false;
                        if (button && button.isConnected) { button.disabled = false; button.classList.remove('bcs-invoice-loading-057'); button.textContent = button.dataset.originalText || 'Generuj fakturę'; }
                    });
            };

            document.addEventListener('click', (event) => {
                const button = event.target.closest('button[name="bcs_crm_action"][value="invoice_generate"]');
                if (!button) return;
                const form = button.closest('form');
                if (!form) return;
                event.preventDefault();
                event.stopImmediatePropagation();
                run(form, button);
            }, true);
            document.addEventListener('submit', (event) => {
                const form = event.target;
                if (!(form instanceof HTMLFormElement) || !invoiceAction(form, event.submitter)) return;
                event.preventDefault();
                event.stopImmediatePropagation();
                run(form, event.submitter || form.querySelector('button[type="submit"],button:not([type])'));
            }, true);
            document.addEventListener('click', (event) => {
                if (event.target.closest('[data-bcs-invoice-close-057]') || event.target.id === 'bcs-invoice-result-modal-057') closeModal();
            });
            document.addEventListener('keydown', (event) => { if (event.key === 'Escape') closeModal(); });
        })();
        </script>
        <?php
    }

    public static function admin_invoice_modal(): void {
        if (!self::is_registrations_page()) return;
        ?>
        <div id="bcs-invoice-result-modal-057" class="bcs-contact-modal" hidden>
            <div class="bcs-contact-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="bcs-invoice-result-title-057">
                <button type="button" class="bcs-contact-modal__close" data-bcs-invoice-close-057 aria-label="Zamknij">×</button>
                <div class="bcs-invoice-result-body-057">
                    <div class="bcs-invoice-result-icon-057" data-bcs-invoice-icon-057>…</div>
                    <h2 id="bcs-invoice-result-title-057" data-bcs-invoice-title-057>Generowanie faktury</h2>
                    <p data-bcs-invoice-message-057>Trwa wykonywanie operacji.</p>
                    <div class="bcs-invoice-result-actions-057">
                        <a class="button button-primary" data-bcs-invoice-download-057 hidden>Pobierz fakturę</a>
                        <button type="button" class="button" data-bcs-invoice-close-057>Zamknij</button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
