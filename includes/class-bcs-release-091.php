<?php
if (!defined('ABSPATH')) exit;

/**
 * 0.91 – podgląd faktury w module Faktury jako wizualizacja zapisanego XML KSeF FA(3).
 *
 * Strona Faktury jest od 0.76 zastępowana przez BCS_Release_076::invoices_page().
 * Tam nadal istnieje przycisk .bcs-invoice-preview, ale nie jest renderowany modal
 * wymagany przez assets/admin.js. 0.91 przywraca modal i przejmuje istniejącą akcję
 * bcs_invoice_view, aby nie pokazywać lokalnego PDF, tylko dokładne dane z XML FA(3).
 */
final class BCS_Release_091 {
    public static function init(): void {
        // Zachowujemy istniejący URL i nonce przycisku z 0.76, ale zmieniamy źródło podglądu.
        remove_action('admin_post_bcs_invoice_view', ['BCS_Invoices', 'stream_invoice']);
        add_action('admin_post_bcs_invoice_view', [__CLASS__, 'preview_ksef_invoice']);
        add_action('admin_footer', [__CLASS__, 'render_invoice_modal'], 9999);
    }

    /**
     * Parsuje FA(3) niezależnie od konkretnego namespace, aby podgląd działał także
     * dla historycznych XML-i zapisanych przez wcześniejszą wersję schemy FA(3).
     *
     * @return array{success:bool,error:string,invoice_number:string,issue_date:string,sale_date:string,currency:string,seller:array,buyer:array,rows:array,net:string,vat:string,gross:string,payment:array,descriptions:array}
     */
    public static function parse_fa3(string $xml): array {
        $empty = [
            'success'=>false,'error'=>'','invoice_number'=>'','issue_date'=>'','sale_date'=>'','currency'=>'PLN',
            'seller'=>['name'=>'','nip'=>'','country'=>'','address_l1'=>'','address_l2'=>''],
            'buyer'=>['name'=>'','nip'=>'','country'=>'','address_l1'=>'','address_l2'=>''],
            'rows'=>[],'net'=>'','vat'=>'','gross'=>'',
            'payment'=>['paid'=>'','date'=>'','form'=>''],
            'descriptions'=>[],
        ];
        if (trim($xml) === '') return $empty + ['error'=>'Pusty XML faktury.'];
        if (!class_exists('DOMDocument')) return $empty + ['error'=>'Brak rozszerzenia DOM wymaganego do wizualizacji XML FA(3).'];

        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        if (!$loaded) {
            $errors = [];
            foreach (libxml_get_errors() as $error) $errors[] = trim((string)$error->message);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            return $empty + ['error'=>$errors ? implode(' ', $errors) : 'Nieprawidłowy XML FA(3).'];
        }
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($dom);
        $text = static function(string $query, ?DOMNode $context = null) use ($xpath): string {
            $node = $xpath->query($query, $context)->item(0);
            return $node ? trim((string)$node->textContent) : '';
        };
        $root = '/*[local-name()="Faktura"]';
        $fa = $root.'/*[local-name()="Fa"]';
        $party = static function(string $name) use ($xpath, $text, $root): array {
            $base = $root.'/*[local-name()="'.$name.'"]';
            return [
                'name'=>$text($base.'/*[local-name()="DaneIdentyfikacyjne"]/*[local-name()="Nazwa"]'),
                'nip'=>$text($base.'/*[local-name()="DaneIdentyfikacyjne"]/*[local-name()="NIP"]'),
                'country'=>$text($base.'/*[local-name()="Adres"]/*[local-name()="KodKraju"]'),
                'address_l1'=>$text($base.'/*[local-name()="Adres"]/*[local-name()="AdresL1"]'),
                'address_l2'=>$text($base.'/*[local-name()="Adres"]/*[local-name()="AdresL2"]'),
            ];
        };

        $rows = [];
        $nodes = $xpath->query($fa.'/*[local-name()="FaWiersz"]');
        if ($nodes) {
            foreach ($nodes as $node) {
                $rows[] = [
                    'number'=>$text('./*[local-name()="NrWierszaFa"]', $node),
                    'name'=>$text('./*[local-name()="P_7"]', $node),
                    'unit'=>$text('./*[local-name()="P_8A"]', $node),
                    'quantity'=>$text('./*[local-name()="P_8B"]', $node),
                    'unit_net'=>$text('./*[local-name()="P_9A"]', $node),
                    'net'=>$text('./*[local-name()="P_11"]', $node),
                    'vat_rate'=>$text('./*[local-name()="P_12"]', $node),
                ];
            }
        }

        $descriptions = [];
        $extras = $xpath->query($fa.'/*[local-name()="DodatkowyOpis"]');
        if ($extras) {
            foreach ($extras as $extra) {
                $key = $text('./*[local-name()="Klucz"]', $extra);
                $value = $text('./*[local-name()="Wartosc"]', $extra);
                if ($key !== '' || $value !== '') $descriptions[] = ['key'=>$key, 'value'=>$value];
            }
        }

        $net = $text($fa.'/*[local-name()="P_13_1"]');
        if ($net === '') $net = $text($fa.'/*[local-name()="P_13_7"]');
        $vat = $text($fa.'/*[local-name()="P_14_1"]');
        if ($vat === '') $vat = '0.00';

        return [
            'success'=>true,
            'error'=>'',
            'invoice_number'=>$text($fa.'/*[local-name()="P_2"]'),
            'issue_date'=>$text($fa.'/*[local-name()="P_1"]'),
            'sale_date'=>$text($fa.'/*[local-name()="P_6"]'),
            'currency'=>$text($fa.'/*[local-name()="KodWaluty"]') ?: 'PLN',
            'seller'=>$party('Podmiot1'),
            'buyer'=>$party('Podmiot2'),
            'rows'=>$rows,
            'net'=>$net,
            'vat'=>$vat,
            'gross'=>$text($fa.'/*[local-name()="P_15"]'),
            'payment'=>[
                'paid'=>$text($fa.'/*[local-name()="Platnosc"]/*[local-name()="Zaplacono"]'),
                'date'=>$text($fa.'/*[local-name()="Platnosc"]/*[local-name()="DataZaplaty"]'),
                'form'=>$text($fa.'/*[local-name()="Platnosc"]/*[local-name()="FormaPlatnosci"]'),
            ],
            'descriptions'=>$descriptions,
        ];
    }

    private static function invoice(int $invoiceId): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT i.*, r.child_first_name, r.child_last_name, c.name camp_name, o.name organizer_name '
            .'FROM '.BCS_DB::table('invoices').' i '
            .'JOIN '.BCS_DB::table('registrations').' r ON r.id=i.registration_id '
            .'JOIN '.BCS_DB::table('camps').' c ON c.id=r.camp_id '
            .'JOIN '.BCS_DB::table('organizers').' o ON o.id=i.organizer_id '
            .'WHERE i.id=%d',
            $invoiceId
        )) ?: null;
    }

    private static function xml_path(object $invoice): string {
        $path = trim((string)($invoice->ksef_xml_path ?? ''));
        if ($path === '') return '';
        $real = realpath($path);
        $base = realpath((string)wp_upload_dir()['basedir']);
        if (!$real || !$base || !is_file($real)) return '';
        $prefix = rtrim($base, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if (!str_starts_with($real, $prefix)) return '';
        return $real;
    }

    private static function h(string $value): string { return esc_html($value !== '' ? $value : '—'); }
    private static function money(string $value, string $currency): string {
        if ($value === '') return '—';
        return esc_html(number_format((float)$value, 2, ',', ' ').' '.$currency);
    }

    private static function party_html(string $label, array $party): string {
        $address = array_values(array_filter([
            trim((string)($party['address_l1'] ?? '')),
            trim((string)($party['address_l2'] ?? '')),
        ], static fn(string $v): bool => $v !== ''));
        return '<section class="party"><h2>'.esc_html($label).'</h2>'
            .'<strong>'.self::h((string)($party['name'] ?? '')).'</strong>'
            .((string)($party['nip'] ?? '') !== '' ? '<p>NIP: '.self::h((string)$party['nip']).'</p>' : '<p>Brak identyfikatora NIP</p>')
            .'<p>'.($address ? implode('<br>', array_map([__CLASS__, 'h'], $address)) : '—').'</p>'
            .'</section>';
    }

    private static function render_document(object $invoice, array $data): string {
        $currency = (string)($data['currency'] ?: 'PLN');
        $status = trim((string)($invoice->ksef_status_description ?? ''));
        if ($status === '') $status = trim((string)($invoice->ksef_status ?? '')) ?: '—';
        $rows = '';
        foreach ((array)$data['rows'] as $row) {
            $rows .= '<tr><td>'.self::h((string)$row['number']).'</td><td class="item-name">'.self::h((string)$row['name']).'</td>'
                .'<td>'.self::h((string)$row['unit']).'</td><td class="num">'.self::h((string)$row['quantity']).'</td>'
                .'<td class="num">'.self::money((string)$row['unit_net'], $currency).'</td>'
                .'<td class="num">'.self::money((string)$row['net'], $currency).'</td><td>'.self::h((string)$row['vat_rate']).'</td></tr>';
        }
        if ($rows === '') $rows = '<tr><td colspan="7" class="empty">Brak pozycji FaWiersz w zapisanym XML.</td></tr>';

        $extras = '';
        foreach ((array)$data['descriptions'] as $description) {
            $extras .= '<div class="extra"><span>'.self::h((string)$description['key']).'</span><strong>'.self::h((string)$description['value']).'</strong></div>';
        }

        $ksefNumber = trim((string)($invoice->ksef_number ?? ''));
        $payment = (array)$data['payment'];
        $paid = (string)($payment['paid'] ?? '') === '1' ? 'Tak' : ((string)($payment['paid'] ?? '') === '2' ? 'Nie' : (string)($payment['paid'] ?? ''));

        return '<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>Wizualizacja KSeF – '.self::h((string)$data['invoice_number']).'</title><style>'
            .'html{background:#eef1f5}*{box-sizing:border-box}body{margin:0;padding:28px;font-family:Arial,Helvetica,sans-serif;color:#172033;background:#eef1f5}.sheet{max-width:1080px;margin:0 auto;background:#fff;border:1px solid #dfe4ea;border-radius:16px;box-shadow:0 12px 38px rgba(15,23,42,.08);overflow:hidden}.top{padding:26px 30px;background:#f8fafc;border-bottom:5px solid #f97316;display:flex;justify-content:space-between;gap:24px}.eyebrow{font-size:12px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:#ea580c}.top h1{font-size:30px;margin:6px 0 0}.meta{text-align:right;font-size:13px;color:#475569;line-height:1.7}.meta strong{color:#172033}.notice{margin:22px 30px 0;padding:12px 15px;border-radius:10px;background:#eff6ff;color:#1e40af;font-size:13px}.parties{display:grid;grid-template-columns:1fr 1fr;gap:18px;padding:24px 30px}.party{border:1px solid #e2e8f0;border-radius:12px;padding:17px}.party h2{font-size:12px;text-transform:uppercase;letter-spacing:.07em;color:#64748b;margin:0 0 10px}.party strong{font-size:17px}.party p{margin:7px 0 0;line-height:1.45;color:#475569}.invoice-info{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;padding:0 30px 22px}.info{padding:13px;border-radius:10px;background:#f8fafc}.info span,.extra span{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#64748b;margin-bottom:5px}.info strong{font-size:14px}.table-wrap{padding:0 30px 22px;overflow:auto}table{width:100%;border-collapse:collapse;font-size:13px}th{background:#172033;color:#fff;text-align:left;padding:11px 9px;white-space:nowrap}td{border-bottom:1px solid #e2e8f0;padding:11px 9px;vertical-align:top}.num{text-align:right;white-space:nowrap}.item-name{min-width:260px}.empty{text-align:center;color:#64748b;padding:24px}.bottom{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:20px;padding:0 30px 30px}.extras{display:grid;gap:10px}.extra{border:1px solid #dbeafe;background:#f8fbff;border-radius:10px;padding:12px}.summary{border:1px solid #fed7aa;background:#fff7ed;border-radius:12px;padding:16px}.summary-row{display:flex;justify-content:space-between;gap:12px;padding:7px 0;border-bottom:1px solid #fed7aa}.summary-row:last-child{border-bottom:0}.summary-row.total{font-size:18px;font-weight:800;color:#c2410c;padding-top:12px}.footer{border-top:1px solid #e2e8f0;padding:17px 30px;color:#64748b;font-size:12px;line-height:1.5}@media(max-width:760px){body{padding:10px}.top,.parties,.bottom{grid-template-columns:1fr;display:grid}.meta{text-align:left}.invoice-info{grid-template-columns:1fr}.parties,.top,.table-wrap,.bottom{padding-left:18px;padding-right:18px}.bottom{grid-template-columns:1fr}}</style></head><body><main class="sheet">'
            .'<header class="top"><div><div class="eyebrow">Wizualizacja faktury ustrukturyzowanej FA(3)</div><h1>Faktura '.self::h((string)$data['invoice_number']).'</h1></div><div class="meta"><strong>Status KSeF:</strong> '.self::h($status).'<br><strong>Numer KSeF:</strong> '.self::h($ksefNumber).'</div></header>'
            .'<div class="notice">Podgląd jest tworzony bezpośrednio z zapisanego XML FA(3) tej faktury. Nie generuje ani nie zmienia dokumentu.</div>'
            .'<div class="parties">'.self::party_html('Sprzedawca', (array)$data['seller']).self::party_html('Nabywca', (array)$data['buyer']).'</div>'
            .'<div class="invoice-info"><div class="info"><span>Data wystawienia</span><strong>'.self::h((string)$data['issue_date']).'</strong></div><div class="info"><span>Data sprzedaży</span><strong>'.self::h((string)$data['sale_date']).'</strong></div><div class="info"><span>Waluta</span><strong>'.self::h($currency).'</strong></div></div>'
            .'<div class="table-wrap"><table><thead><tr><th>Lp.</th><th>Nazwa towaru / usługi</th><th>Jm.</th><th>Ilość</th><th>Cena netto</th><th>Wartość netto</th><th>VAT</th></tr></thead><tbody>'.$rows.'</tbody></table></div>'
            .'<div class="bottom"><div><div class="extras">'.$extras.'</div><div class="invoice-info" style="padding:14px 0 0;grid-template-columns:repeat(3,1fr)"><div class="info"><span>Zapłacono</span><strong>'.self::h($paid).'</strong></div><div class="info"><span>Data zapłaty</span><strong>'.self::h((string)($payment['date'] ?? '')).'</strong></div><div class="info"><span>Forma płatności – kod FA(3)</span><strong>'.self::h((string)($payment['form'] ?? '')).'</strong></div></div></div>'
            .'<aside class="summary"><div class="summary-row"><span>Netto</span><strong>'.self::money((string)$data['net'], $currency).'</strong></div><div class="summary-row"><span>VAT</span><strong>'.self::money((string)$data['vat'], $currency).'</strong></div><div class="summary-row total"><span>Brutto</span><strong>'.self::money((string)$data['gross'], $currency).'</strong></div></aside></div>'
            .'<footer class="footer">Źródło podglądu: zapisany XML KSeF / FA(3) przypisany do faktury #'.(int)$invoice->id.'.</footer></main></body></html>';
    }

    private static function render_error(object $invoice, string $message): string {
        return '<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Podgląd KSeF</title><style>'
            .'body{margin:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#172033;padding:32px}.box{max-width:760px;margin:40px auto;background:#fff;border:1px solid #e2e8f0;border-left:5px solid #f97316;border-radius:14px;padding:26px;box-shadow:0 10px 30px rgba(15,23,42,.07)}h1{margin:0 0 12px;font-size:24px}p{line-height:1.55;color:#475569}</style></head><body><div class="box"><h1>Podgląd KSeF niedostępny</h1><p><strong>Faktura:</strong> '.self::h((string)$invoice->invoice_number).'</p><p>'.self::h($message).'</p><p>Podgląd nie tworzy nowego XML ani nie zmienia faktury.</p></div></body></html>';
    }

    public static function preview_ksef_invoice(): void {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień.');
        $invoiceId = absint($_GET['invoice_id'] ?? 0);
        if (!$invoiceId) wp_die('Nieprawidłowy identyfikator faktury.');
        check_admin_referer('bcs_invoice_view_'.$invoiceId);
        $invoice = self::invoice($invoiceId);
        if (!$invoice) wp_die('Nie znaleziono faktury.');

        $path = self::xml_path($invoice);
        if ($path === '') {
            $html = self::render_error($invoice, 'Dla tej faktury nie ma dostępnego zapisanego pliku XML FA(3) KSeF.');
        } else {
            $xml = (string)file_get_contents($path);
            $data = self::parse_fa3($xml);
            $html = !empty($data['success'])
                ? self::render_document($invoice, $data)
                : self::render_error($invoice, 'Nie udało się odczytać XML FA(3): '.(string)($data['error'] ?? 'nieznany błąd'));
        }

        nocache_headers();
        header('Content-Type: text/html; charset=UTF-8');
        header('X-Frame-Options: SAMEORIGIN');
        echo $html;
        exit;
    }

    /** Przywraca modal pominięty przez stronę Faktury z 0.76. */
    public static function render_invoice_modal(): void {
        if (!is_admin() || !current_user_can('manage_options')) return;
        if (sanitize_key(wp_unslash($_GET['page'] ?? '')) !== 'bcs-invoices') return;
        ?>
        <style>
            .bcs-invoice-modal[hidden]{display:none!important}.bcs-invoice-modal{position:fixed;inset:0;z-index:100100;background:rgba(15,23,42,.72);display:flex;align-items:center;justify-content:center;padding:24px}.bcs-invoice-modal__dialog{position:relative;width:min(1180px,calc(100vw - 48px));height:calc(100vh - 48px);background:#fff;border-radius:16px;box-shadow:0 28px 80px rgba(0,0,0,.3);overflow:hidden}.bcs-invoice-modal iframe{display:block;width:100%;height:100%;border:0;background:#eef1f5}.bcs-invoice-modal__close{position:absolute;right:13px;top:12px;z-index:3;width:38px;height:38px;border:1px solid #cbd5e1;border-radius:999px;background:#fff;color:#172033;font-size:25px;line-height:32px;cursor:pointer;box-shadow:0 3px 10px rgba(15,23,42,.12)}.bcs-invoice-modal__close:hover{background:#fff7ed;border-color:#fb923c;color:#c2410c}@media(max-width:782px){.bcs-invoice-modal{padding:8px}.bcs-invoice-modal__dialog{width:calc(100vw - 16px);height:calc(100vh - 16px);border-radius:10px}}
        </style>
        <div id="bcs-invoice-modal" class="bcs-invoice-modal" hidden aria-modal="true" role="dialog" aria-label="Wizualizacja faktury KSeF">
            <div class="bcs-invoice-modal__dialog">
                <button type="button" class="bcs-invoice-modal__close" aria-label="Zamknij">×</button>
                <iframe title="Wizualizacja faktury KSeF FA(3)" src="about:blank"></iframe>
            </div>
        </div>
        <script>
        (()=>{
            document.querySelectorAll('.bcs-invoice-preview').forEach(button=>button.setAttribute('title','Podgląd KSeF'));
            document.addEventListener('keydown',event=>{
                if(event.key!=='Escape')return;
                const modal=document.getElementById('bcs-invoice-modal');
                if(!modal||modal.hidden)return;
                modal.hidden=true;const frame=modal.querySelector('iframe');if(frame)frame.src='about:blank';document.body.style.overflow='';
            });
        })();
        </script>
        <?php
    }
}
