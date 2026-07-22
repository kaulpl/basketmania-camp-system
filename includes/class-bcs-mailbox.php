<?php
if (!defined('ABSPATH')) exit;

class BCS_Mailbox {
    public static function init(): void {
        // Menu rejestrowane centralnie w BCS_Admin.
        add_action('admin_init', [__CLASS__, 'actions']);
        add_action('bcs_mailbox_sync_event', [__CLASS__, 'sync']);
        add_filter('cron_schedules', [__CLASS__, 'cron_schedules']);
        self::ensure_schedule();
    }

    public static function cron_schedules(array $schedules): array {
        $schedules['bcs_five_minutes'] = ['interval'=>300, 'display'=>'Co 5 minut (Basketmania)'];
        $schedules['bcs_ten_minutes'] = ['interval'=>600, 'display'=>'Co 10 minut (Basketmania)'];
        return $schedules;
    }

    public static function ensure_schedule(): void {
        $s = get_option('bcs_settings', []);
        $enabled = !empty($s['imap_enabled']);
        $frequency = in_array($s['imap_frequency'] ?? 'bcs_ten_minutes', ['bcs_five_minutes','bcs_ten_minutes','hourly'], true) ? $s['imap_frequency'] : 'bcs_ten_minutes';
        $event = wp_get_scheduled_event('bcs_mailbox_sync_event');
        if (!$enabled) {
            if ($event) wp_unschedule_event($event->timestamp, 'bcs_mailbox_sync_event');
            return;
        }
        if (!$event || $event->schedule !== $frequency) {
            if ($event) wp_unschedule_event($event->timestamp, 'bcs_mailbox_sync_event');
            wp_schedule_event(time() + 60, $frequency, 'bcs_mailbox_sync_event');
        }
    }

    public static function unread_count(): int { global $wpdb; return (int)$wpdb->get_var("SELECT COUNT(*) FROM ".BCS_DB::table('mail_messages')." WHERE direction='inbound' AND is_read=0"); }

    public static function menu(): void {
        global $wpdb;
        $unread = (int)$wpdb->get_var("SELECT COUNT(*) FROM ".BCS_DB::table('mail_messages')." WHERE direction='inbound' AND is_read=0");
        $badge = $unread ? ' <span class="awaiting-mod count-'.$unread.'"><span class="plugin-count">'.$unread.'</span></span>' : '';
        add_submenu_page('bcs-dashboard', 'Poczta', 'Poczta'.$badge, 'manage_options', 'bcs-mailbox', [__CLASS__, 'page']);
    }

    public static function actions(): void {
        if (!current_user_can('manage_options')) return;
        if (isset($_POST['bcs_mail_sync'])) {
            check_admin_referer('bcs_mail_sync');
            $result = self::sync();
            wp_safe_redirect(add_query_arg(['page'=>'bcs-mailbox','synced'=>1,'new'=>absint($result['new'] ?? 0),'errors'=>absint($result['errors'] ?? 0)], admin_url('admin.php'))); exit;
        }
        if (isset($_POST['bcs_mail_assign'])) {
            check_admin_referer('bcs_mail_assign'); global $wpdb;
            $mid=absint($_POST['message_id']??0); $rid=absint($_POST['registration_id']??0);
            if($mid&&$rid)$wpdb->update(BCS_DB::table('mail_messages'),['registration_id'=>$rid,'match_confidence'=>'manual'],['id'=>$mid]);
            self::redirect_message($mid,'assigned');
        }
        if (isset($_POST['bcs_mail_reply'])) {
            check_admin_referer('bcs_mail_reply'); global $wpdb;
            $mid=absint($_POST['message_id']??0); $m=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".BCS_DB::table('mail_messages')." WHERE id=%d",$mid));
            if($m){$subject=sanitize_text_field(wp_unslash($_POST['subject']??('Re: '.$m->subject)));$body=wp_kses_post(wp_unslash($_POST['body']??''));$ok=BCS_Mailer::send($m->sender_email,$subject,nl2br($body),[],[],(int)$m->registration_id);BCS_Utils::log('mailbox_reply',['message_id'=>$mid,'success'=>$ok],(int)$m->registration_id,null);self::redirect_message($mid,$ok?'replied':'reply_error');}
        }
        if (isset($_POST['bcs_mail_create_registration'])) {
            check_admin_referer('bcs_mail_create_registration'); self::create_registration_from_message();
        }
        if (isset($_GET['bcs_mail_read']) && isset($_GET['_wpnonce'])) {
            $mid=absint($_GET['bcs_mail_read']); if(wp_verify_nonce($_GET['_wpnonce'],'bcs_mail_read_'.$mid)){global $wpdb;$wpdb->update(BCS_DB::table('mail_messages'),['is_read'=>1],['id'=>$mid]);self::redirect_message($mid,'read');}
        }
    }

    private static function redirect_message(int $mid, string $notice): void { wp_safe_redirect(add_query_arg(['page'=>'bcs-mailbox','message'=>$mid,'notice'=>$notice],admin_url('admin.php')));exit; }

    public static function record_outgoing(string $to,string $subject,string $body,bool $success,int $registration_id=0,string $message_id=''): void {
        global $wpdb;
        if(!$registration_id)$registration_id=self::match_registration($to,$subject,$body);
        $s=get_option('bcs_settings',[]); $from=sanitize_email((string)($s['mail_from_email']??$s['company_email']??get_option('admin_email')));
        $wpdb->insert(BCS_DB::table('mail_messages'),[
            'registration_id'=>$registration_id?:null,'direction'=>'outbound','mailbox_uid'=>null,'message_id'=>$message_id,'in_reply_to'=>'','references_header'=>'',
            'sender_email'=>$from,'sender_name'=>(string)($s['mail_from_name']??'Basketmania Camp'),'recipient_email'=>sanitize_email($to),'subject'=>$subject,
            'body_text'=>self::content_text((object)['body_html'=>$body,'body_text'=>'']),'body_html'=>$body,'status'=>$success?'sent':'failed','match_confidence'=>$registration_id?'system':'unmatched','is_read'=>1,
            'received_at'=>BCS_Utils::now(),'created_at'=>BCS_Utils::now()
        ]);
    }

    public static function message_id_for(int $registration_id): string {
        $host=parse_url(home_url(),PHP_URL_HOST) ?: 'basketmania.local';
        return '<bcs-r'.$registration_id.'-'.wp_generate_uuid4().'@'.$host.'>';
    }

    public static function sync(): array {
        $result=['new'=>0,'errors'=>0,'message'=>'']; $s=get_option('bcs_settings',[]);
        if(empty($s['imap_enabled'])) return ['new'=>0,'errors'=>1,'message'=>'Synchronizacja IMAP jest wyłączona.'];
        if(!function_exists('imap_open')) { update_option('bcs_last_imap_result',['success'=>false,'message'=>'Rozszerzenie PHP IMAP nie jest dostępne.','time'=>BCS_Utils::now()]); return ['new'=>0,'errors'=>1,'message'=>'Brak rozszerzenia PHP IMAP.']; }
        $host=trim((string)($s['imap_host']??''));$port=absint($s['imap_port']??993);$enc=$s['imap_encryption']??'ssl';$folder=trim((string)($s['imap_folder']??'INBOX'))?:'INBOX';$user=trim((string)($s['imap_username']??''));$pass=(string)($s['imap_password']??'');
        if($host===''||$user===''||$pass==='') return ['new'=>0,'errors'=>1,'message'=>'Niepełna konfiguracja IMAP.'];
        $flags='/imap'.($enc==='ssl'?'/ssl':($enc==='tls'?'/tls':'')).(!empty($s['imap_novalidate'])?'/novalidate-cert':'');$mailbox='{'.$host.':'.$port.$flags.'}'.$folder;
        $imap=@imap_open($mailbox,$user,$pass,0,1); if(!$imap){$err=imap_last_error()?:'Nie można połączyć z IMAP.';update_option('bcs_last_imap_result',['success'=>false,'message'=>$err,'time'=>BCS_Utils::now()]);return ['new'=>0,'errors'=>1,'message'=>$err];}
        $uids=imap_search($imap,'ALL',SE_UID)?:[]; rsort($uids); $uids=array_slice($uids,0,200); global $wpdb;
        foreach(array_reverse($uids) as $uid){
            $exists=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".BCS_DB::table('mail_messages')." WHERE direction='inbound' AND mailbox_uid=%s",(string)$uid)); if($exists)continue;
            $msgno=imap_msgno($imap,$uid);$h=imap_headerinfo($imap,$msgno); if(!$h){$result['errors']++;continue;}
            $raw=imap_fetchheader($imap,$msgno);$subject=self::decode((string)($h->subject??'(bez tematu)'));$from=$h->from[0]??null;$sender=$from?sanitize_email(($from->mailbox??'').'@'.($from->host??'')):'';$sender_name=$from?self::decode((string)($from->personal??'')):'';
            $body=self::fetch_body($imap,$msgno);$mid=self::header_value($raw,'Message-ID');$irt=self::header_value($raw,'In-Reply-To');$refs=self::header_value($raw,'References');$rid=self::match_incoming($sender,$subject,$body,$irt,$refs,$raw);$date=!empty($h->udate)?wp_date('Y-m-d H:i:s',(int)$h->udate,BCS_Utils::timezone()):BCS_Utils::now();
            $wpdb->insert(BCS_DB::table('mail_messages'),['registration_id'=>$rid?:null,'direction'=>'inbound','mailbox_uid'=>(string)$uid,'message_id'=>$mid,'in_reply_to'=>$irt,'references_header'=>$refs,'sender_email'=>$sender,'sender_name'=>$sender_name,'recipient_email'=>sanitize_email((string)($s['imap_username']??'')),'subject'=>$subject,'body_text'=>self::content_text((object)['body_html'=>$body,'body_text'=>'']),'body_html'=>$body,'status'=>'received','match_confidence'=>$rid?'automatic':'unmatched','is_read'=>0,'received_at'=>$date,'created_at'=>BCS_Utils::now()]);
            $result['new']++;
        }
        imap_close($imap);update_option('bcs_last_imap_result',['success'=>true,'message'=>'Synchronizacja zakończona.','new'=>$result['new'],'errors'=>$result['errors'],'time'=>BCS_Utils::now()]);return $result;
    }

    private static function header_value(string $raw,string $name): string { if(preg_match('/^'.preg_quote($name,'/').':\s*(.+(?:\r?\n[ \t].+)*)/mi',$raw,$m))return trim(preg_replace('/\r?\n[ \t]+/',' ',$m[1]));return ''; }
    private static function decode(string $v): string { if(function_exists('imap_mime_header_decode')){$parts=imap_mime_header_decode($v);$out='';foreach($parts as $p){$txt=$p->text;if(strtoupper($p->charset??'DEFAULT')!=='DEFAULT'&&function_exists('mb_convert_encoding'))$txt=@mb_convert_encoding($txt,'UTF-8',$p->charset);$out.=$txt;}return $out;}return $v; }
    private static function fetch_body($imap,int $msgno): string { $structure=imap_fetchstructure($imap,$msgno);$html='';$plain='';self::walk_parts($imap,$msgno,$structure,'',$html,$plain);return $html!==''?$html:nl2br(esc_html($plain!==''?$plain:(string)imap_body($imap,$msgno))); }
    private static function walk_parts($imap,int $msgno,$part,string $num,string &$html,string &$plain): void { if(!empty($part->parts)){foreach($part->parts as $i=>$p)self::walk_parts($imap,$msgno,$p,$num===''?(string)($i+1):$num.'.'.($i+1),$html,$plain);return;} $data=$num===''?imap_body($imap,$msgno):imap_fetchbody($imap,$msgno,$num);if(($part->encoding??0)==3)$data=base64_decode($data);elseif(($part->encoding??0)==4)$data=quoted_printable_decode($data);$sub=strtoupper((string)($part->subtype??''));if($sub==='HTML')$html.=$data;elseif($sub==='PLAIN')$plain.=$data; }

    private static function match_incoming(string $email,string $subject,string $body,string $irt,string $refs,string $raw): int {
        global $wpdb;
        if(preg_match('/X-BCS-Registration-ID:\s*(\d+)/i',$raw,$m))return absint($m[1]);
        if(preg_match('/(?:BCS-R|zgłoszenie\s*#)(\d+)/iu',$subject.' '.$body,$m))return absint($m[1]);
        foreach(array_filter([$irt,$refs]) as $ref){$rid=$wpdb->get_var($wpdb->prepare("SELECT registration_id FROM ".BCS_DB::table('mail_messages')." WHERE message_id<>'' AND %s LIKE CONCAT('%%',message_id,'%%') AND registration_id IS NOT NULL ORDER BY id DESC LIMIT 1",$ref));if($rid)return (int)$rid;}
        return self::match_registration($email,$subject,$body);
    }
    private static function match_registration(string $email,string $subject='',string $body=''): int { global $wpdb;$rows=$wpdb->get_results($wpdb->prepare("SELECT r.id,c.name camp_name,r.child_first_name,r.child_last_name FROM ".BCS_DB::table('registrations')." r JOIN ".BCS_DB::table('camps')." c ON c.id=r.camp_id WHERE r.parent_email=%s AND r.status<>'cancelled' ORDER BY r.created_at DESC",sanitize_email($email)));if(count($rows)===1)return (int)$rows[0]->id;$hay=mb_strtolower($subject.' '.$body);foreach($rows as $r){if(str_contains($hay,mb_strtolower((string)$r->camp_name))||str_contains($hay,mb_strtolower(trim($r->child_first_name.' '.$r->child_last_name))))return (int)$r->id;}return 0; }

    private static function create_registration_from_message(): void { check_admin_referer('bcs_mail_create_registration');global $wpdb;$mid=absint($_POST['message_id']??0);$camp_id=absint($_POST['camp_id']??0);$m=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".BCS_DB::table('mail_messages')." WHERE id=%d",$mid));$camp=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".BCS_DB::table('camps')." WHERE id=%d",$camp_id));if(!$m||!$camp)self::redirect_message($mid,'create_error');$name=trim((string)$m->sender_name);$parts=preg_split('/\s+/',$name,2);$now=BCS_Utils::now();$wpdb->insert(BCS_DB::table('registrations'),['camp_id'=>$camp_id,'public_token'=>hash('sha256',wp_generate_uuid4().microtime(true)),'status'=>'new','form_status'=>'incomplete','source'=>'email','parent_first_name'=>sanitize_text_field($parts[0]??'Do uzupełnienia'),'parent_last_name'=>sanitize_text_field($parts[1]??''),'parent_email'=>sanitize_email($m->sender_email),'parent_phone'=>'Do uzupełnienia','child_first_name'=>'Do uzupełnienia','child_last_name'=>'','total_amount'=>(float)$camp->price,'paid_amount'=>0,'medical_notes'=>'Zgłoszenie utworzone z wiadomości e-mail #'.$mid.'.','created_at'=>$now,'updated_at'=>$now]);$rid=(int)$wpdb->insert_id;if($rid){$wpdb->update(BCS_DB::table('mail_messages'),['registration_id'=>$rid,'match_confidence'=>'created'],['id'=>$mid]);BCS_Utils::log('registration_created_from_email',['message_id'=>$mid],$rid,null);self::redirect_message($mid,'created');}self::redirect_message($mid,'create_error'); }


    /**
     * Zwraca właściwą treść HTML wiadomości bez nagłówków dokumentu, CSS,
     * tytułu technicznego i layoutu koperty. Działa również dla starszych rekordów.
     */
    public static function content_html(object $message): string {
        $html = trim((string)($message->body_html ?? ''));
        if ($html === '') {
            return nl2br(esc_html(self::content_text($message)));
        }

        // Wiadomości generowane przez Basketmania Camp: pobieramy wyłącznie
        // komórkę z właściwą treścią, bez nagłówka, stopki i arkusza CSS.
        if (stripos($html, 'bcs-mail-content') !== false
            && preg_match('/<td\b[^>]*class=("|\')[^"\']*bcs-mail-content[^"\']*\1[^>]*>(.*?)<\/td>/is', $html, $m)) {
            return wp_kses_post(trim($m[2]));
        }

        // Dla pełnych dokumentów HTML usuwamy elementy techniczne i zwracamy
        // tylko zawartość BODY. Zapobiega to wyświetlaniu CSS jako tekstu.
        if (class_exists('DOMDocument') && preg_match('/<(?:html|head|body|style)\b/i', $html)) {
            $previous = libxml_use_internal_errors(true);
            $dom = new DOMDocument('1.0', 'UTF-8');
            $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            if ($loaded) {
                foreach (['style','script','title','head','meta','link'] as $tag) {
                    $nodes = $dom->getElementsByTagName($tag);
                    for ($i = $nodes->length - 1; $i >= 0; $i--) {
                        $node = $nodes->item($i);
                        if ($node && $node->parentNode) $node->parentNode->removeChild($node);
                    }
                }
                $body = $dom->getElementsByTagName('body')->item(0);
                $out = '';
                if ($body) {
                    foreach ($body->childNodes as $child) $out .= $dom->saveHTML($child);
                } else {
                    $out = $dom->saveHTML();
                }
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
                return wp_kses_post(trim($out));
            }
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $html = preg_replace('#<(style|script|title|head)[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $html = preg_replace('#</?(?:html|body)[^>]*>#i', '', $html) ?? $html;
        return wp_kses_post(trim($html));
    }

    /** Zwraca czysty tekst wiadomości do list i skrótów. */
    public static function content_text(object $message): string {
        $html = trim((string)($message->body_html ?? ''));
        if ($html !== '') {
            $clean = self::content_html((object)['body_html'=>$html, 'body_text'=>'']);
            $text = html_entity_decode(wp_strip_all_tags($clean, true), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        } else {
            $text = (string)($message->body_text ?? '');
            // Ochrona dla starych rekordów, w których body_text powstało przez
            // strip_tags pełnego dokumentu i zaczyna się od reguł CSS.
            $text = preg_replace('/^(?:body,table,td,a\{.*?)(?=(?:Dzień dobry|Szanown|Witaj|Cześć|Dzień dobry,|$))/isu', '', $text) ?? $text;
        }
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
        $len = mb_strlen($text);
        if ($len > 20 && $len % 2 === 0 && trim(mb_substr($text,0,$len/2)) === trim(mb_substr($text,$len/2))) {
            $text = trim(mb_substr($text,0,$len/2));
        }
        return $text;
    }

    public static function message_preview(object $message): string {
        return '<div class="bcs-mail-body">'.self::content_html($message).'</div>';
    }

    private static function status_badge(object $message): string {
        if (($message->direction ?? '') === 'outbound') {
            $failed = (($message->status ?? '') === 'failed');
            return '<span class="bcs-mail-status '.($failed ? 'is-failed' : 'is-sent').'">'.($failed ? 'Błąd wysyłki' : 'Wysłana').'</span>';
        }
        if (empty($message->is_read)) return '<span class="bcs-mail-status is-new">Nowa</span>';
        return '<span class="bcs-mail-status is-received">Odebrana</span>';
    }

    public static function page(): void {
        global $wpdb;
        $mid = absint($_GET['message'] ?? 0);
        $filter = sanitize_key($_GET['filter'] ?? 'inbox');
        $last = get_option('bcs_last_imap_result', []);

        $counts = [
            'inbox' => (int)$wpdb->get_var("SELECT COUNT(*) FROM ".BCS_DB::table('mail_messages')." WHERE direction='inbound'"),
            'unassigned' => (int)$wpdb->get_var("SELECT COUNT(*) FROM ".BCS_DB::table('mail_messages')." WHERE direction='inbound' AND registration_id IS NULL"),
            'sent' => (int)$wpdb->get_var("SELECT COUNT(*) FROM ".BCS_DB::table('mail_messages')." WHERE direction='outbound'"),
            'unread' => (int)$wpdb->get_var("SELECT COUNT(*) FROM ".BCS_DB::table('mail_messages')." WHERE direction='inbound' AND is_read=0"),
        ];

        echo '<div class="wrap bcs-admin bcs-mailbox">';
        echo '<div class="bcs-page-head bcs-mailbox-head"><div><div class="bcs-eyebrow">CENTRUM KOMUNIKACJI</div><h1>Poczta</h1><p>Odbieraj wiadomości, odpowiadaj i przypisuj korespondencję do zgłoszeń.</p></div><form method="post">';
        wp_nonce_field('bcs_mail_sync');
        echo '<button class="button button-primary bcs-mail-sync" name="bcs_mail_sync" value="1"><span class="dashicons dashicons-update"></span> Synchronizuj pocztę</button></form></div>';

        if (isset($_GET['synced'])) echo '<div class="notice notice-success"><p>Pobrano nowych wiadomości: '.absint($_GET['new'] ?? 0).'. Błędy: '.absint($_GET['errors'] ?? 0).'.</p></div>';
        if (!function_exists('imap_open')) echo '<div class="notice notice-warning"><p>Serwer PHP nie ma aktywnego rozszerzenia IMAP. Historia maili wysłanych przez system działa, ale odbiór wiadomości wymaga włączenia PHP IMAP na hostingu.</p></div>';

        echo '<div class="bcs-mailbox-stats">'
            .'<div class="bcs-mail-stat"><span class="dashicons dashicons-email-alt"></span><div><strong>'.$counts['inbox'].'</strong><span>Odebrane</span></div></div>'
            .'<div class="bcs-mail-stat"><span class="dashicons dashicons-marker"></span><div><strong>'.$counts['unread'].'</strong><span>Nieprzeczytane</span></div></div>'
            .'<div class="bcs-mail-stat"><span class="dashicons dashicons-warning"></span><div><strong>'.$counts['unassigned'].'</strong><span>Nieprzypisane</span></div></div>'
            .'<div class="bcs-mail-stat"><span class="dashicons dashicons-yes-alt"></span><div><strong>'.$counts['sent'].'</strong><span>Wysłane</span></div></div>'
            .'</div>';

        if ($last) {
            echo '<div class="bcs-mail-sync-info"><span class="dashicons dashicons-clock"></span><span><strong>Ostatnia synchronizacja:</strong> '.esc_html((string)($last['time'] ?? '—')).' — '.esc_html((string)($last['message'] ?? '')).'</span></div>';
        }

        echo '<nav class="bcs-mail-tabs">'
            .'<a class="'.($filter === 'inbox' ? 'is-active' : '').'" href="'.esc_url(admin_url('admin.php?page=bcs-mailbox&filter=inbox')).'"><span class="dashicons dashicons-inbox"></span> Odebrane <em>'.$counts['inbox'].'</em></a>'
            .'<a class="'.($filter === 'unassigned' ? 'is-active' : '').'" href="'.esc_url(admin_url('admin.php?page=bcs-mailbox&filter=unassigned')).'"><span class="dashicons dashicons-warning"></span> Nieprzypisane <em>'.$counts['unassigned'].'</em></a>'
            .'<a class="'.($filter === 'sent' ? 'is-active' : '').'" href="'.esc_url(admin_url('admin.php?page=bcs-mailbox&filter=sent')).'"><span class="dashicons dashicons-upload"></span> Wysłane <em>'.$counts['sent'].'</em></a>'
            .'</nav>';

        if ($mid) {
            self::message_view($mid);
            echo '</div>';
            return;
        }

        $where = $filter === 'sent' ? "direction='outbound'" : ($filter === 'unassigned' ? "direction='inbound' AND registration_id IS NULL" : "direction='inbound'");
        $rows = $wpdb->get_results("SELECT m.*,r.child_first_name,r.child_last_name,c.name camp_name FROM ".BCS_DB::table('mail_messages')." m LEFT JOIN ".BCS_DB::table('registrations')." r ON r.id=m.registration_id LEFT JOIN ".BCS_DB::table('camps')." c ON c.id=r.camp_id WHERE $where ORDER BY received_at DESC LIMIT 200");

        echo '<div class="bcs-mail-list">';
        if (!$rows) echo '<div class="bcs-mail-empty"><span class="dashicons dashicons-email"></span><h2>Brak wiadomości</h2><p>W tym folderze nie ma jeszcze żadnej korespondencji.</p></div>';
        foreach ($rows as $m) {
            $who = $m->direction === 'inbound' ? ($m->sender_name ?: $m->sender_email) : $m->recipient_email;
            $email = $m->direction === 'inbound' ? $m->sender_email : $m->recipient_email;
            $link = admin_url('admin.php?page=bcs-mailbox&filter='.$filter.'&message='.$m->id);
            $classes = 'bcs-mail-row'.((!$m->is_read && $m->direction === 'inbound') ? ' is-unread' : '');
            echo '<article class="'.$classes.'">';
            echo '<a class="bcs-mail-row-link" href="'.esc_url($link).'">';
            echo '<div class="bcs-mail-avatar">'.esc_html(mb_strtoupper(mb_substr((string)$who, 0, 1))).'</div>';
            echo '<div class="bcs-mail-row-main"><div class="bcs-mail-row-top"><strong>'.esc_html($who).'</strong><time>'.esc_html(BCS_Utils::format_datetime($m->received_at)).'</time></div>';
            echo '<h3>'.esc_html($m->subject ?: '(bez tematu)').'</h3><p>'.esc_html(wp_trim_words(self::content_text($m), 22)).'</p><small>'.esc_html($email).'</small></div>';
            echo '<div class="bcs-mail-row-side">'.self::status_badge($m);
            if ($m->registration_id) {
                echo '<span class="bcs-mail-linkage">#'.(int)$m->registration_id.' · '.esc_html(trim($m->child_first_name.' '.$m->child_last_name)).'<small>'.esc_html((string)$m->camp_name).'</small></span>';
            } else {
                echo '<span class="bcs-mail-unassigned">Nieprzypisana</span>';
            }
            echo '<span class="dashicons dashicons-arrow-right-alt2"></span></div>';
            echo '</a></article>';
        }
        echo '</div></div>';
    }

    private static function message_view(int $mid): void {
        global $wpdb;
        $m = $wpdb->get_row($wpdb->prepare("SELECT * FROM ".BCS_DB::table('mail_messages')." WHERE id=%d", $mid));
        if (!$m) { echo '<div class="bcs-panel"><p>Nie znaleziono wiadomości.</p></div>'; return; }
        $wpdb->update(BCS_DB::table('mail_messages'), ['is_read'=>1], ['id'=>$mid]);
        $regs = $wpdb->get_results("SELECT r.id,r.parent_first_name,r.parent_last_name,r.child_first_name,r.child_last_name,c.name camp_name FROM ".BCS_DB::table('registrations')." r JOIN ".BCS_DB::table('camps')." c ON c.id=r.camp_id WHERE r.status<>'cancelled' ORDER BY r.created_at DESC LIMIT 300");
        $camps = $wpdb->get_results("SELECT id,name FROM ".BCS_DB::table('camps')." WHERE status<>'archived' ORDER BY start_date DESC");

        echo '<div class="bcs-mail-view-nav"><a href="'.esc_url(admin_url('admin.php?page=bcs-mailbox')).'"><span class="dashicons dashicons-arrow-left-alt2"></span> Wróć do poczty</a></div>';
        echo '<section class="bcs-panel bcs-mail-message-card"><header class="bcs-mail-message-head"><div><div class="bcs-mail-message-meta">'.self::status_badge($m).'<span>'.esc_html(BCS_Utils::format_datetime($m->received_at)).'</span></div><h2>'.esc_html($m->subject ?: '(bez tematu)').'</h2><p><strong>'.($m->direction === 'inbound' ? 'Od' : 'Do').':</strong> '.esc_html($m->direction === 'inbound' ? (($m->sender_name ? $m->sender_name.' · ' : '').$m->sender_email) : $m->recipient_email).'</p></div></header>';
        echo self::message_preview($m);
        echo '</section>';

        echo '<div class="bcs-mail-view-grid"><section class="bcs-panel"><div class="bcs-panel-heading"><span class="dashicons dashicons-admin-links"></span><div><h2>Powiązanie ze zgłoszeniem</h2><p>Przypisz wiadomość do właściwego uczestnika i turnusu.</p></div></div>';
        if ($m->registration_id) echo '<div class="bcs-mail-current-link"><span class="dashicons dashicons-yes-alt"></span><span>Wiadomość przypisana do <a href="'.esc_url(admin_url('admin.php?page=bcs-registrations&view='.$m->registration_id)).'"><strong>zgłoszenia #'.(int)$m->registration_id.'</strong></a>.</span></div>';
        echo '<form method="post" class="bcs-mail-form">'; wp_nonce_field('bcs_mail_assign');
        echo '<input type="hidden" name="message_id" value="'.$mid.'"><label><span>Zgłoszenie</span><select name="registration_id" required><option value="">Wybierz zgłoszenie</option>';
        foreach ($regs as $r) echo '<option value="'.$r->id.'" '.selected((int)$m->registration_id, (int)$r->id, false).'>#'.$r->id.' — '.esc_html($r->child_first_name.' '.$r->child_last_name.' / '.$r->camp_name.' / '.$r->parent_first_name.' '.$r->parent_last_name).'</option>';
        echo '</select></label><button class="button button-primary" name="bcs_mail_assign" value="1">Przypisz wiadomość</button></form>';

        if (!$m->registration_id) {
            echo '<div class="bcs-mail-create-registration"><h3>Utwórz zgłoszenie z wiadomości</h3><form method="post" class="bcs-mail-form">'; wp_nonce_field('bcs_mail_create_registration');
            echo '<input type="hidden" name="message_id" value="'.$mid.'"><label><span>Turnus</span><select name="camp_id" required><option value="">Wybierz turnus</option>';
            foreach ($camps as $c) echo '<option value="'.$c->id.'">'.esc_html($c->name).'</option>';
            echo '</select></label><button class="button button-primary" name="bcs_mail_create_registration" value="1">Utwórz zgłoszenie</button><p class="description">System uzupełni adres e-mail i nazwę nadawcy. Pozostałe dane będą oznaczone do uzupełnienia.</p></form></div>';
        }
        echo '</section>';

        if ($m->direction === 'inbound') {
            echo '<section class="bcs-panel"><div class="bcs-panel-heading"><span class="dashicons dashicons-undo"></span><div><h2>Odpowiedz</h2><p>Wiadomość zostanie wysłana przez konto skonfigurowane w Ustawienia → E-MAIL.</p></div></div><form method="post" class="bcs-mail-form">'; wp_nonce_field('bcs_mail_reply');
            echo '<input type="hidden" name="message_id" value="'.$mid.'"><label><span>Temat</span><input class="large-text" name="subject" value="'.esc_attr(str_starts_with($m->subject, 'Re:') ? $m->subject : 'Re: '.$m->subject).'"></label><label><span>Treść</span><textarea class="large-text" rows="10" name="body" required></textarea></label><button class="button button-primary" name="bcs_mail_reply" value="1">Wyślij odpowiedź</button></form></section>';
        }
        echo '</div>';
    }

}
