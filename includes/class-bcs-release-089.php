<?php
if (!defined('ABSPATH')) exit;

/**
 * 0.89 – poprawny parser odpowiedzi IMAP + niebieski znacznik Nowa korespondencja.
 *
 * Problem historyczny:
 * - BCS_Mailbox wybierał HTML zawsze, gdy istniał jakikolwiek text/html,
 * - części MIME były sklejane bez pełnego respektowania charsetu i dyspozycji,
 * - cytowana historia odpowiedzi mogła zostać pokazana zamiast nowej wiadomości rodzica.
 *
 * 0.89 przejmuje synchronizację IMAP, czyści odpowiedzi do najnowszej treści,
 * próbuje ponownie pobrać nieprzeczytane starsze wiadomości po UID oraz dodaje
 * niezależny od "Wymagane działanie" stan "Nowa korespondencja".
 */
final class BCS_Release_089 {
    private const REPAIR_OPTION = 'bcs_release_089_mail_repair';
    private const REPAIR_VERSION = '0.89';

    public static function init(): void {
        // Cron: zastępujemy parser z BCS_Mailbox, nie ruszając reszty modułu Poczta.
        remove_action('bcs_mailbox_sync_event', ['BCS_Mailbox', 'sync']);
        add_action('bcs_mailbox_sync_event', [__CLASS__, 'sync']);

        // Ręczna synchronizacja musi wykonać się przed BCS_Mailbox::actions(), które
        // po synchronizacji robi redirect i kończy request.
        add_action('admin_init', [__CLASS__, 'handle_manual_sync'], 1);
        add_action('admin_init', [__CLASS__, 'mark_open_mail_read'], 2);
        add_action('admin_init', [__CLASS__, 'maybe_repair_unread'], 3);

        add_action('wp_ajax_bcs_mark_registration_mail_read_089', [__CLASS__, 'ajax_mark_registration_read']);
        add_action('admin_footer', [__CLASS__, 'render_unread_indicators'], 9999);
    }

    /** Czysta funkcja: zwraca wyłącznie najnowszą odpowiedź, bez cytowanej historii. */
    public static function extract_latest_reply_text(string $text): string {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = str_replace(["\xC2\xA0", "\xE2\x80\x8B"], [' ', ''], $text);
        $text = trim($text);
        if ($text === '') return '';

        // Szukamy najwcześniejszego typowego separatora historii odpowiedzi.
        $probe = "\n".$text;
        $patterns = [
            '/\n\s*On\s+.{3,350}?\s+wrote:\s*(?:\n|$)/isu',
            '/\n\s*W dniu\s+.{3,350}?(?:napisał|napisała|napisał\(a\)|pisze):\s*(?:\n|$)/isu',
            '/\n\s*Dnia\s+.{3,350}?(?:napisał|napisała|napisał\(a\)):\s*(?:\n|$)/isu',
            '/\n\s*Wiadomość napisana przez\s+.{3,350}?:\s*(?:\n|$)/isu',
            '/\n\s*.{2,250}<[^>\n]+>\s+(?:napisał|napisała|wrote):\s*(?:\n|$)/isu',
            '/\n\s*-{2,}\s*(?:Original Message|Wiadomość oryginalna)\s*-{2,}\s*(?:\n|$)/iu',
            '/\n\s*Begin forwarded message:\s*(?:\n|$)/iu',
            '/\n\s*Początek przekazywanej wiadomości:\s*(?:\n|$)/iu',
            '/\n\s*Od:\s*[^\n]+\n\s*(?:Wysłano|Data|Do|Temat):/iu',
            '/\n\s*From:\s*[^\n]+\n\s*(?:Sent|Date|To|Subject):/iu',
        ];
        $cut = null;
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $probe, $m, PREG_OFFSET_CAPTURE)) {
                $offset = (int)$m[0][1];
                if ($cut === null || $offset < $cut) $cut = $offset;
            }
        }
        if ($cut !== null) {
            $text = trim(substr($probe, 1, max(0, $cut - 1)));
        }

        // Usuwamy pojedyncze linie cytowania, które zostały bez pełnego separatora.
        $lines = explode("\n", $text);
        $clean = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*>/u', $line)) continue;
            $clean[] = rtrim($line);
        }
        $text = trim(implode("\n", $clean));
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;
        return trim($text);
    }

    /** Czysta funkcja pomocnicza dla odpowiedzi HTML (Gmail/Outlook/Apple Mail). */
    public static function extract_latest_reply_from_html(string $html): string {
        $html = trim($html);
        if ($html === '') return '';

        if (class_exists('DOMDocument')) {
            $previous = libxml_use_internal_errors(true);
            $dom = new DOMDocument('1.0', 'UTF-8');
            $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            if ($loaded) {
                $xpath = new DOMXPath($dom);
                // Cytowane odpowiedzi najczęstszych klientów pocztowych.
                foreach ([
                    '//blockquote',
                    '//*[contains(concat(" ", normalize-space(@class), " "), " gmail_quote ")]',
                    '//*[contains(concat(" ", normalize-space(@class), " "), " yahoo_quoted ")]',
                    '//*[contains(@class,"moz-cite-prefix")]',
                ] as $query) {
                    $nodes = $xpath->query($query);
                    if (!$nodes) continue;
                    $copy = [];
                    foreach ($nodes as $node) $copy[] = $node;
                    foreach ($copy as $node) if ($node->parentNode) $node->parentNode->removeChild($node);
                }

                // Outlook: divRplyFwdMsg jest początkiem starej historii. Usuwamy marker
                // i wszystkie kolejne rodzeństwa w tym samym kontenerze.
                $markers = $xpath->query('//*[@id="divRplyFwdMsg"]');
                if ($markers && $markers->length) {
                    $copy = [];
                    foreach ($markers as $node) $copy[] = $node;
                    foreach ($copy as $node) {
                        $cursor = $node;
                        while ($cursor) {
                            $next = $cursor->nextSibling;
                            if ($cursor->parentNode) $cursor->parentNode->removeChild($cursor);
                            $cursor = $next;
                        }
                    }
                }
                $html = (string)$dom->saveHTML();
            }
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        } else {
            $html = preg_replace('#<blockquote\b[^>]*>.*?</blockquote>#is', '', $html) ?? $html;
        }

        // Zachowujemy naturalne podziały linii przed strip_tags().
        $html = preg_replace('/<br\s*\/?>/iu', "\n", $html) ?? $html;
        $html = preg_replace('/<\/(?:p|div|li|tr|h[1-6])>/iu', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return self::extract_latest_reply_text($text);
    }

    private static function decode_header(string $value): string {
        if (!function_exists('imap_mime_header_decode')) return $value;
        $parts = imap_mime_header_decode($value);
        $out = '';
        foreach ((array)$parts as $part) {
            $text = (string)($part->text ?? '');
            $charset = strtoupper((string)($part->charset ?? 'DEFAULT'));
            if ($charset !== 'DEFAULT' && $charset !== 'UTF-8' && function_exists('mb_convert_encoding')) {
                $text = (string)@mb_convert_encoding($text, 'UTF-8', $charset);
            }
            $out .= $text;
        }
        return $out;
    }

    private static function header_value(string $raw, string $name): string {
        if (preg_match('/^'.preg_quote($name, '/').':\s*(.+(?:\r?\n[ \t].+)*)/mi', $raw, $m)) {
            return trim((string)(preg_replace('/\r?\n[ \t]+/', ' ', $m[1]) ?? $m[1]));
        }
        return '';
    }

    private static function part_charset(object $part): string {
        foreach (['parameters', 'dparameters'] as $property) {
            foreach ((array)($part->{$property} ?? []) as $parameter) {
                if (strtolower((string)($parameter->attribute ?? '')) === 'charset') {
                    return trim((string)($parameter->value ?? ''));
                }
            }
        }
        return '';
    }

    private static function part_is_attachment(object $part): bool {
        $disposition = strtoupper((string)($part->disposition ?? ''));
        if ($disposition === 'ATTACHMENT') return true;
        foreach (['parameters', 'dparameters'] as $property) {
            foreach ((array)($part->{$property} ?? []) as $parameter) {
                $attribute = strtolower((string)($parameter->attribute ?? ''));
                if (in_array($attribute, ['filename', 'name'], true) && trim((string)($parameter->value ?? '')) !== '') return true;
            }
        }
        return false;
    }

    private static function decode_part(string $data, int $encoding, string $charset): string {
        if ($encoding === 3) {
            $decoded = base64_decode($data, true);
            if ($decoded !== false) $data = $decoded;
        } elseif ($encoding === 4) {
            $data = quoted_printable_decode($data);
        }

        $charset = trim($charset);
        if ($charset !== '' && !in_array(strtoupper($charset), ['UTF-8', 'US-ASCII', 'DEFAULT'], true) && function_exists('mb_convert_encoding')) {
            $converted = @mb_convert_encoding($data, 'UTF-8', $charset);
            if (is_string($converted) && $converted !== '') $data = $converted;
        } elseif (function_exists('mb_check_encoding') && !mb_check_encoding($data, 'UTF-8') && function_exists('mb_convert_encoding')) {
            $converted = @mb_convert_encoding($data, 'UTF-8', ['Windows-1250', 'ISO-8859-2', 'ISO-8859-1']);
            if (is_string($converted) && $converted !== '') $data = $converted;
        }
        return $data;
    }

    private static function walk_parts($imap, int $msgno, object $part, string $number, array &$plain, array &$html): void {
        if (!empty($part->parts)) {
            foreach ((array)$part->parts as $i => $child) {
                $childNumber = $number === '' ? (string)($i + 1) : $number.'.'.($i + 1);
                self::walk_parts($imap, $msgno, $child, $childNumber, $plain, $html);
            }
            return;
        }
        if (self::part_is_attachment($part)) return;
        $type = (int)($part->type ?? 0);
        if ($type !== 0) return; // wyłącznie MIME TEXT
        $subtype = strtoupper((string)($part->subtype ?? 'PLAIN'));
        if (!in_array($subtype, ['PLAIN', 'HTML'], true)) return;

        $flags = defined('FT_PEEK') ? FT_PEEK : 0;
        $data = $number === '' ? (string)imap_body($imap, $msgno, $flags) : (string)imap_fetchbody($imap, $msgno, $number, $flags);
        $data = self::decode_part($data, (int)($part->encoding ?? 0), self::part_charset($part));
        if (trim($data) === '') return;
        if ($subtype === 'HTML') $html[] = $data;
        else $plain[] = $data;
    }

    private static function fetch_message_content($imap, int $msgno): array {
        $plainParts = [];
        $htmlParts = [];
        $structure = imap_fetchstructure($imap, $msgno);
        if ($structure) self::walk_parts($imap, $msgno, $structure, '', $plainParts, $htmlParts);

        $plainRaw = trim(implode("\n\n", $plainParts));
        $htmlRaw = trim(implode("\n", $htmlParts));
        if ($plainRaw === '' && $htmlRaw === '') {
            $flags = defined('FT_PEEK') ? FT_PEEK : 0;
            $plainRaw = (string)imap_body($imap, $msgno, $flags);
        }

        $plain = self::extract_latest_reply_text($plainRaw);
        $fromHtml = self::extract_latest_reply_from_html($htmlRaw);
        // text/plain jest preferowane, bo klient pocztowy najczęściej umieszcza tam
        // dokładnie tę samą odpowiedź bez layoutu. HTML jest bezpiecznym fallbackiem.
        $text = $plain !== '' ? $plain : $fromHtml;
        if ($text === '') $text = self::extract_latest_reply_text(strip_tags($htmlRaw));

        return [
            'text' => trim($text),
            'html' => trim($text) !== '' ? '<div class="bcs-inbound-clean-089">'.nl2br(esc_html(trim($text))).'</div>' : '',
        ];
    }

    private static function mailbox_config(): array {
        $s = get_option('bcs_settings', []);
        $host = trim((string)($s['imap_host'] ?? ''));
        $port = absint($s['imap_port'] ?? 993);
        $enc = (string)($s['imap_encryption'] ?? 'ssl');
        $folder = trim((string)($s['imap_folder'] ?? 'INBOX')) ?: 'INBOX';
        $user = trim((string)($s['imap_username'] ?? ''));
        $pass = (string)($s['imap_password'] ?? '');
        $flags = '/imap'.($enc === 'ssl' ? '/ssl' : ($enc === 'tls' ? '/tls' : '')).(!empty($s['imap_novalidate']) ? '/novalidate-cert' : '');
        return [
            'settings' => $s,
            'valid' => !empty($s['imap_enabled']) && $host !== '' && $user !== '' && $pass !== '',
            'mailbox' => '{'.$host.':'.$port.$flags.'}'.$folder,
            'user' => $user,
            'pass' => $pass,
        ];
    }

    private static function match_registration(string $email, string $subject = '', string $body = ''): int {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT r.id,c.name camp_name,r.child_first_name,r.child_last_name FROM ".BCS_DB::table('registrations')." r JOIN ".BCS_DB::table('camps')." c ON c.id=r.camp_id WHERE r.parent_email=%s AND r.status<>'cancelled' ORDER BY r.created_at DESC",
            sanitize_email($email)
        ));
        if (count((array)$rows) === 1) return (int)$rows[0]->id;
        $hay = function_exists('mb_strtolower') ? mb_strtolower($subject.' '.$body) : strtolower($subject.' '.$body);
        foreach ((array)$rows as $r) {
            $camp = function_exists('mb_strtolower') ? mb_strtolower((string)$r->camp_name) : strtolower((string)$r->camp_name);
            $participant = trim((string)$r->child_first_name.' '.(string)$r->child_last_name);
            $participant = function_exists('mb_strtolower') ? mb_strtolower($participant) : strtolower($participant);
            if (($camp !== '' && str_contains($hay, $camp)) || ($participant !== '' && str_contains($hay, $participant))) return (int)$r->id;
        }
        return 0;
    }

    private static function match_incoming(string $email, string $subject, string $body, string $inReplyTo, string $references, string $raw): int {
        global $wpdb;
        if (preg_match('/X-BCS-Registration-ID:\s*(\d+)/i', $raw, $m)) return absint($m[1]);
        if (preg_match('/(?:BCS-R|zgłoszenie\s*#)(\d+)/iu', $subject.' '.$body, $m)) return absint($m[1]);
        foreach (array_filter([$inReplyTo, $references]) as $ref) {
            $rid = $wpdb->get_var($wpdb->prepare(
                "SELECT registration_id FROM ".BCS_DB::table('mail_messages')." WHERE message_id<>'' AND %s LIKE CONCAT('%%',message_id,'%%') AND registration_id IS NOT NULL ORDER BY id DESC LIMIT 1",
                $ref
            ));
            if ($rid) return (int)$rid;
        }
        return self::match_registration($email, $subject, $body);
    }

    /** Pełna synchronizacja używana przez cron oraz ręczny przycisk Poczta. */
    public static function sync(): array {
        $result = ['new'=>0, 'errors'=>0, 'message'=>''];
        $cfg = self::mailbox_config();
        if (empty($cfg['settings']['imap_enabled'])) return ['new'=>0, 'errors'=>1, 'message'=>'Synchronizacja IMAP jest wyłączona.'];
        if (!function_exists('imap_open')) {
            update_option('bcs_last_imap_result', ['success'=>false, 'message'=>'Rozszerzenie PHP IMAP nie jest dostępne.', 'time'=>BCS_Utils::now()]);
            return ['new'=>0, 'errors'=>1, 'message'=>'Brak rozszerzenia PHP IMAP.'];
        }
        if (empty($cfg['valid'])) return ['new'=>0, 'errors'=>1, 'message'=>'Niepełna konfiguracja IMAP.'];

        $imap = @imap_open($cfg['mailbox'], $cfg['user'], $cfg['pass'], 0, 1);
        if (!$imap) {
            $error = imap_last_error() ?: 'Nie można połączyć z IMAP.';
            update_option('bcs_last_imap_result', ['success'=>false, 'message'=>$error, 'time'=>BCS_Utils::now()]);
            return ['new'=>0, 'errors'=>1, 'message'=>$error];
        }

        $uids = imap_search($imap, 'ALL', SE_UID) ?: [];
        rsort($uids);
        $uids = array_slice($uids, 0, 200);
        global $wpdb;
        foreach (array_reverse($uids) as $uid) {
            $exists = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM ".BCS_DB::table('mail_messages')." WHERE direction='inbound' AND mailbox_uid=%s",
                (string)$uid
            ));
            if ($exists) continue;
            $msgno = imap_msgno($imap, $uid);
            if (!$msgno) { $result['errors']++; continue; }
            $header = imap_headerinfo($imap, $msgno);
            if (!$header) { $result['errors']++; continue; }

            $rawHeader = (string)imap_fetchheader($imap, $msgno);
            $subject = self::decode_header((string)($header->subject ?? '(bez tematu)'));
            $from = $header->from[0] ?? null;
            $sender = $from ? sanitize_email((string)($from->mailbox ?? '').'@'.(string)($from->host ?? '')) : '';
            $senderName = $from ? self::decode_header((string)($from->personal ?? '')) : '';
            $body = self::fetch_message_content($imap, $msgno);
            $messageId = self::header_value($rawHeader, 'Message-ID');
            $inReplyTo = self::header_value($rawHeader, 'In-Reply-To');
            $references = self::header_value($rawHeader, 'References');
            $registrationId = self::match_incoming($sender, $subject, $body['text'], $inReplyTo, $references, $rawHeader);
            $date = !empty($header->udate) ? wp_date('Y-m-d H:i:s', (int)$header->udate, BCS_Utils::timezone()) : BCS_Utils::now();

            $inserted = $wpdb->insert(BCS_DB::table('mail_messages'), [
                'registration_id'=>$registrationId ?: null,
                'direction'=>'inbound',
                'mailbox_uid'=>(string)$uid,
                'message_id'=>$messageId,
                'in_reply_to'=>$inReplyTo,
                'references_header'=>$references,
                'sender_email'=>$sender,
                'sender_name'=>$senderName,
                'recipient_email'=>sanitize_email((string)($cfg['settings']['imap_username'] ?? '')),
                'subject'=>$subject,
                'body_text'=>$body['text'],
                'body_html'=>$body['html'],
                'status'=>'received',
                'match_confidence'=>$registrationId ? 'automatic' : 'unmatched',
                'is_read'=>0,
                'received_at'=>$date,
                'created_at'=>BCS_Utils::now(),
            ]);
            if ($inserted) {
                $result['new']++;
                if ($registrationId) BCS_Utils::log('inbound_mail_received_089', ['subject'=>$subject], $registrationId, null);
            } else {
                $result['errors']++;
            }
        }
        imap_close($imap);
        $result['message'] = 'Synchronizacja zakończona.';
        update_option('bcs_last_imap_result', ['success'=>true, 'message'=>$result['message'], 'new'=>$result['new'], 'errors'=>$result['errors'], 'time'=>BCS_Utils::now()]);
        return $result;
    }

    public static function handle_manual_sync(): void {
        if (!is_admin() || !current_user_can('manage_options') || !isset($_POST['bcs_mail_sync'])) return;
        check_admin_referer('bcs_mail_sync');
        $result = self::sync();
        $url = add_query_arg([
            'page'=>'bcs-mailbox', 'synced'=>1,
            'new'=>absint($result['new'] ?? 0), 'errors'=>absint($result['errors'] ?? 0),
        ], admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
    }

    /** Naprawia lokalnie starsze nieprzeczytane rekordy, jeśli treść jest jeszcze w HTML. */
    private static function repair_saved_unread_locally(): int {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT id,body_text,body_html FROM ".BCS_DB::table('mail_messages')." WHERE direction='inbound' AND is_read=0 ORDER BY id DESC LIMIT 200");
        $changed = 0;
        foreach ((array)$rows as $row) {
            $plain = self::extract_latest_reply_text((string)($row->body_text ?? ''));
            $html = self::extract_latest_reply_from_html((string)($row->body_html ?? ''));
            $text = $plain !== '' ? $plain : $html;
            if ($text === '') continue;
            $newHtml = '<div class="bcs-inbound-clean-089">'.nl2br(esc_html($text)).'</div>';
            if ($text === trim((string)$row->body_text) && str_contains((string)$row->body_html, 'bcs-inbound-clean-089')) continue;
            $wpdb->update(BCS_DB::table('mail_messages'), ['body_text'=>$text, 'body_html'=>$newHtml], ['id'=>(int)$row->id]);
            $changed++;
        }
        return $changed;
    }

    /** Ponownie pobiera po UID nieprzeczytane wiadomości – odzyskuje też text/plain utracony przez stary parser. */
    private static function repair_unread_from_imap(): bool {
        if (!function_exists('imap_open')) return false;
        $cfg = self::mailbox_config();
        if (empty($cfg['valid'])) return false;
        $imap = @imap_open($cfg['mailbox'], $cfg['user'], $cfg['pass'], 0, 1);
        if (!$imap) return false;

        global $wpdb;
        $rows = $wpdb->get_results("SELECT id,mailbox_uid FROM ".BCS_DB::table('mail_messages')." WHERE direction='inbound' AND is_read=0 AND mailbox_uid IS NOT NULL AND mailbox_uid<>'' ORDER BY id DESC LIMIT 100");
        foreach ((array)$rows as $row) {
            $msgno = imap_msgno($imap, (int)$row->mailbox_uid);
            if (!$msgno) continue;
            $body = self::fetch_message_content($imap, $msgno);
            if (trim((string)$body['text']) === '') continue;
            $wpdb->update(BCS_DB::table('mail_messages'), ['body_text'=>$body['text'], 'body_html'=>$body['html']], ['id'=>(int)$row->id]);
        }
        imap_close($imap);
        return true;
    }

    public static function maybe_repair_unread(): void {
        if (!is_admin() || !current_user_can('manage_options')) return;
        if ((string)get_option(self::REPAIR_OPTION, '') === self::REPAIR_VERSION) return;
        if (get_transient('bcs_release_089_repair_attempt')) return;
        set_transient('bcs_release_089_repair_attempt', 1, HOUR_IN_SECONDS);

        self::repair_saved_unread_locally();
        $cfg = self::mailbox_config();
        $remoteOk = empty($cfg['settings']['imap_enabled']) ? true : self::repair_unread_from_imap();
        if ($remoteOk) update_option(self::REPAIR_OPTION, self::REPAIR_VERSION, false);
    }

    /** Otwarcie wiadomości w module Poczta traktujemy jako przeczytanie. */
    public static function mark_open_mail_read(): void {
        if (!is_admin() || !current_user_can('manage_options')) return;
        if (sanitize_key(wp_unslash($_GET['page'] ?? '')) !== 'bcs-mailbox') return;
        $messageId = absint($_GET['message'] ?? 0);
        if (!$messageId) return;
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "UPDATE ".BCS_DB::table('mail_messages')." SET is_read=1 WHERE id=%d AND direction='inbound'",
            $messageId
        ));
    }

    public static function ajax_mark_registration_read(): void {
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Brak uprawnień.'], 403);
        $id = absint($_POST['registration_id'] ?? 0);
        $nonce = sanitize_text_field(wp_unslash($_POST['nonce'] ?? ''));
        if (!$id || !wp_verify_nonce($nonce, 'bcs_mail_read_089_'.$id)) wp_send_json_error(['message'=>'Sesja wygasła.'], 403);
        global $wpdb;
        $count = $wpdb->query($wpdb->prepare(
            "UPDATE ".BCS_DB::table('mail_messages')." SET is_read=1 WHERE registration_id=%d AND direction='inbound' AND is_read=0",
            $id
        ));
        BCS_Utils::log('inbound_mail_read_089', ['count'=>(int)$count], $id, null);
        wp_send_json_success(['count'=>(int)$count]);
    }

    private static function unread_map(): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT registration_id,COUNT(*) unread_count FROM ".BCS_DB::table('mail_messages')." WHERE direction='inbound' AND is_read=0 AND registration_id IS NOT NULL GROUP BY registration_id"
        );
        $map = [];
        foreach ((array)$rows as $row) if ((int)$row->registration_id > 0) $map[(int)$row->registration_id] = (int)$row->unread_count;
        return $map;
    }

    /** Niebieski marker na liście zgłoszeń i w Karcie Zgłoszenia. */
    public static function render_unread_indicators(): void {
        if (!is_admin() || !current_user_can('manage_options')) return;
        if (sanitize_key(wp_unslash($_GET['page'] ?? '')) !== 'bcs-registrations') return;
        $map = self::unread_map();
        if (!$map) return;
        $viewId = absint($_GET['view'] ?? 0);
        $nonce = $viewId ? wp_create_nonce('bcs_mail_read_089_'.$viewId) : '';
        $config = [
            'unread'=>$map,
            'viewId'=>$viewId,
            'ajaxUrl'=>admin_url('admin-ajax.php'),
            'nonce'=>$nonce,
        ];
        ?>
        <style>
            .bcs-new-mail-marker-089{display:inline-flex;align-items:center;gap:4px;margin:4px 0 0 6px;padding:3px 7px;border:1px solid #9ec5fe;border-radius:999px;background:#e7f1ff;color:#0a58ca;font-size:11px;font-weight:700;line-height:1.25;vertical-align:middle;white-space:nowrap}
            .bcs-new-mail-marker-089 .dashicons{width:14px;height:14px;font-size:14px;line-height:14px}
            .bcs-page-head h1>.bcs-new-mail-marker-089{margin-left:10px;transform:translateY(-2px);font-size:12px;padding:5px 9px}
            .bcs-accordion-panel summary .bcs-new-mail-marker-089{margin:0 8px 0 0}
        </style>
        <script>
        (()=>{
            const cfg=<?php echo wp_json_encode($config); ?>;
            const badge=()=>{
                const el=document.createElement('span');
                el.className='bcs-new-mail-marker-089';
                el.innerHTML='<span class="dashicons dashicons-email-alt" aria-hidden="true"></span><span>Nowa korespondencja</span>';
                return el;
            };
            document.querySelectorAll('tr[data-id]').forEach(row=>{
                const id=Number(row.dataset.id||0);if(!cfg.unread[id])return;
                const cell=row.querySelector('td:first-child');if(cell&&!cell.querySelector('.bcs-new-mail-marker-089'))cell.appendChild(badge());
            });
            if(!cfg.viewId||!cfg.unread[cfg.viewId])return;
            const h1=document.querySelector('.bcs-page-head h1');
            if(h1&&!h1.querySelector('.bcs-new-mail-marker-089'))h1.appendChild(badge());
            const correspondence=[...document.querySelectorAll('.bcs-accordion-panel')].find(panel=>/Korespondencja e-mail/i.test(panel.querySelector('summary strong')?.textContent||''));
            if(correspondence){
                const summary=correspondence.querySelector('summary');
                const statuses=summary?.querySelector('.bcs-accordion-hint')?.parentElement||summary;
                if(statuses&&!statuses.querySelector('.bcs-new-mail-marker-089'))statuses.insertBefore(badge(),statuses.firstChild);
            }
            let marking=false;
            const markRead=async()=>{
                if(marking)return;marking=true;
                const body=new URLSearchParams({action:'bcs_mark_registration_mail_read_089',registration_id:String(cfg.viewId),nonce:cfg.nonce});
                try{
                    const response=await fetch(cfg.ajaxUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body:body.toString()});
                    const json=await response.json();
                    if(response.ok&&json.success)document.querySelectorAll('.bcs-new-mail-marker-089').forEach(el=>el.remove());
                }catch(e){}finally{marking=false;}
            };
            document.addEventListener('click',event=>{
                const open=event.target.closest('.bcs-mail-preview');
                if(open&&correspondence?.contains(open))markRead();
            },true);
        })();
        </script>
        <?php
    }
}
