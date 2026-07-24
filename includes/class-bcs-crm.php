<?php
if (!defined('ABSPATH')) exit;

class BCS_CRM {
    public static function init(): void {
        add_action('admin_init', [__CLASS__, 'actions']);
        add_action('wp_ajax_bcs_list_quick_action_02010', [__CLASS__, 'ajax_list_quick_action']);
        add_action('wp_ajax_bcs_card_action_02021', [__CLASS__, 'ajax_card_action']);
    }

    public static function ajax_card_action(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message'=>'Brak uprawnień.'], 403);
        }

        $id = absint($_POST['registration_id'] ?? 0);
        $action = sanitize_key(wp_unslash($_POST['card_action'] ?? ''));
        $nonce = sanitize_text_field(wp_unslash($_POST['nonce'] ?? ''));
        if (!$id || !in_array($action, ['send_agreement','cancel_registration'], true)) {
            wp_send_json_error(['message'=>'Brak danych wymaganych do wykonania działania.'], 422);
        }

        if ($action === 'send_agreement') {
            if (!wp_verify_nonce($nonce, 'bcs_workflow_single_'.$id.'_send_agreement')) {
                wp_send_json_error(['message'=>'Sesja wygasła. Odśwież Kartę Zgłoszenia i spróbuj ponownie.'], 403);
            }
            $ok = BCS_Workflow_Engine::execute('send_agreement', $id);
            if (!$ok) {
                wp_send_json_error([
                    'message'=>BCS_Workflow::last_error() ?: 'Nie udało się wysłać umowy. Sprawdź etap zgłoszenia i ustawienia komunikacji.',
                ], 409);
            }
            wp_send_json_success(['message'=>'Umowa została wysłana do podpisu.']);
        }

        if (!wp_verify_nonce($nonce, 'bcs_crm_'.$id)) {
            wp_send_json_error(['message'=>'Sesja wygasła. Odśwież Kartę Zgłoszenia i spróbuj ponownie.'], 403);
        }
        if (!self::cancel_registration($id)) {
            wp_send_json_error(['message'=>'Nie udało się anulować zgłoszenia. Mogło zostać anulowane wcześniej.'], 409);
        }
        wp_send_json_success(['message'=>'Zgłoszenie zostało anulowane.']);
    }

    public static function ajax_list_quick_action(): void {
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Brak uprawnień.'], 403);
        $id = absint($_POST['registration_id'] ?? 0);
        $action = sanitize_key(wp_unslash($_POST['quick_action'] ?? ''));
        $nonce = sanitize_text_field(wp_unslash($_POST['nonce'] ?? ''));
        if (!$id || !$action) wp_send_json_error(['message'=>'Brak danych szybkiej akcji.'], 422);

        $workflow_actions = ['confirm_registration','send_agreement','send_stripe_link','mark_bank_paid','remind_payment','generate_invoice'];
        if (in_array($action, $workflow_actions, true)) {
            if (!wp_verify_nonce($nonce, 'bcs_workflow_single_'.$id.'_'.$action)) {
                wp_send_json_error(['message'=>'Sesja wygasła. Odśwież listę i spróbuj ponownie.'], 403);
            }
            $ok = BCS_Workflow_Engine::execute($action, $id);
        } else {
            if (!wp_verify_nonce($nonce, 'bcs_crm_'.$id)) {
                wp_send_json_error(['message'=>'Sesja wygasła. Odśwież listę i spróbuj ponownie.'], 403);
            }
            $ok = match ($action) {
                'verify_form' => BCS_Workflow_Engine::verify_form($id),
                'mark_paid' => BCS_Workflow_Engine::mark_bank_paid($id),
                'invoice_generate' => BCS_Workflow_Engine::generate_invoice($id),
                'invoice_send' => self::send_invoice($id),
                default => false,
            };
        }
        if (!$ok) wp_send_json_error(['message'=>'Nie udało się wykonać akcji. Sprawdź aktualny etap zgłoszenia.'], 409);

        $messages = [
            'confirm_registration'=>'Rejestracja została potwierdzona.',
            'verify_form'=>'Formularz Obozowy został zaakceptowany.',
            'send_agreement'=>'Umowa została wysłana.',
            'mark_paid'=>'Wpłata została zaksięgowana.',
            'invoice_generate'=>'Faktura została wygenerowana.',
            'invoice_send'=>'Faktura została wysłana.',
        ];
        $row = self::list_row_state($id);
        if (!$row) wp_send_json_error(['message'=>'Akcja została wykonana, ale nie udało się odświeżyć wiersza zgłoszenia.'], 500);
        $labels = BCS_Workflow_Engine::statuses();
        $due = max(0, (float)$row->total_amount - (float)$row->paid_amount);
        wp_send_json_success([
            'message'=>$messages[$action] ?? 'Akcja została wykonana.',
            'status'=>(string)$row->status,
            'status_label'=>(string)($labels[$row->status] ?? $row->status),
            'status_class'=>self::status_class((string)$row->status),
            'agreement_number'=>(string)($row->agreement_number ?: 'Bez umowy'),
            'paid'=>(float)$row->paid_amount,
            'payment_html'=>'<strong>'.number_format((float)$row->paid_amount,2,',',' ').' zł</strong><br><small>pozostało '.number_format($due,2,',',' ').' zł</small>'.self::payment_reference($row),
            'progress_html'=>self::milestone_badges($row),
            'quick_html'=>self::list_quick_actions_html($row),
            'requires_action'=>!empty($row->requires_action),
            'complete'=>!empty($row->has_sent_invoice) || ($row->invoice_status??'')==='sent',
            'updated_at'=>(string)$row->updated_at,
        ]);
    }

    private static function list_row_state(int $id): ?object {
        global $wpdb;
        $action_condition = BCS_Admin::action_required_condition('r');
        return $wpdb->get_row($wpdb->prepare(
            "SELECT r.*,c.name camp_name,c.start_date,a.agreement_number,a.status agreement_record_status,p.id payment_real_id,p.provider payment_provider,p.external_id payment_external_id,p.paid_at payment_paid_at,
            CASE WHEN (".$action_condition.") THEN 1 ELSE 0 END requires_action,
            EXISTS(SELECT 1 FROM ".BCS_DB::table('logs')." lf WHERE lf.registration_id=r.id AND lf.event_type='camp_form_verified') has_form_verified_log,
            EXISTS(SELECT 1 FROM ".BCS_DB::table('activities')." af WHERE af.registration_id=r.id AND af.activity_type IN ('form_verified','camp_form_verified')) has_form_verified_activity,
            EXISTS(SELECT 1 FROM ".BCS_DB::table('agreements')." au WHERE au.id=r.agreement_id AND au.status='accepted') has_signed_agreement,
            EXISTS(SELECT 1 FROM ".BCS_DB::table('payments')." pp WHERE pp.registration_id=r.id AND pp.status='paid') has_paid_payment,
            EXISTS(SELECT 1 FROM ".BCS_DB::table('invoices')." fi WHERE fi.registration_id=r.id AND fi.status='sent') has_sent_invoice
            FROM ".BCS_DB::table('registrations')." r
            JOIN ".BCS_DB::table('camps')." c ON c.id=r.camp_id
            LEFT JOIN ".BCS_DB::table('agreements')." a ON a.id=r.agreement_id
            LEFT JOIN ".BCS_DB::table('payments')." p ON p.id=r.payment_id
            WHERE r.id=%d LIMIT 1",
            $id
        ));
    }

    private static function list_quick_actions_html(object $r): string {
        $quick='<div class="bcs-inline-actions">';
        if($r->status==='new') $quick.=self::workflow_button((int)$r->id,'confirm_registration','Potwierdź rejestrację');
        elseif(($r->form_status??'')==='complete' && empty($r->form_verified_at)) $quick.=self::list_action_form((int)$r->id,'verify_form','Akceptuj formularz','button');
        elseif(!empty($r->form_verified_at) && ($r->agreement_record_status??'')!=='accepted' && ($r->agreement_record_status??'')!=='pending') $quick.=self::workflow_button((int)$r->id,'send_agreement','Wyślij umowę');
        elseif(($r->agreement_record_status??'')==='accepted' && (float)$r->paid_amount < (float)$r->total_amount) $quick.=self::list_action_form((int)$r->id,'mark_paid','Zaksięguj wpłatę','button');
        elseif($r->invoice_status==='generated') $quick.=self::list_action_form((int)$r->id,'invoice_send','Wyślij fakturę','button');
        elseif(BCS_Workflow_Engine::invoice_available((int)$r->id)) $quick.=self::list_action_form((int)$r->id,'invoice_generate','Generuj fakturę','button');
        else $quick.='<span class="bcs-muted">Brak wymaganej akcji</span>';
        return $quick.'</div>';
    }

    public static function actions(): void {
        if (!current_user_can('manage_options') || empty($_POST['bcs_crm_action'])) return;
        $id = absint($_POST['registration_id'] ?? 0);
        check_admin_referer('bcs_crm_' . $id);
        $action = sanitize_key(wp_unslash($_POST['bcs_crm_action']));
        $note = sanitize_textarea_field(wp_unslash($_POST['note'] ?? ''));
        $ok = false;
        if ($action === 'phone') $ok = self::activity($id, 'phone', 'Wykonano telefon', $note);
        elseif ($action === 'note') $ok = self::activity($id, 'note', 'Notatka', $note);
        elseif ($action === 'task') $ok = self::activity($id, 'task', 'Dodano zadanie', $note);
        elseif ($action === 'portal_send') {
            $ok = BCS_Frontend::send_parent_portal_invite($id, true);
            $invite_result = BCS_Frontend::last_invite_result();
            set_transient('bcs_portal_send_'.get_current_user_id().'_'.$id, $invite_result, 5 * MINUTE_IN_SECONDS);
        }
        elseif ($action === 'verify_form') {
            $ok = BCS_Workflow_Engine::verify_form($id);
            $verify_result = BCS_Workflow_Engine::last_form_verification_result();
            set_transient('bcs_form_verify_'.get_current_user_id().'_'.$id, $verify_result, 5 * MINUTE_IN_SECONDS);
        }
        elseif ($action === 'mark_paid') { $ok = BCS_Workflow_Engine::mark_bank_paid($id); if ($ok) self::activity($id, 'payment', 'Potwierdzono wpłatę', 'Przelew tradycyjny oznaczony jako opłacony.'); }
        elseif ($action === 'invoice_generate') { $ok = BCS_Workflow_Engine::generate_invoice($id); if ($ok) self::activity($id, 'invoice', 'Wygenerowano fakturę', ''); }
        elseif ($action === 'invoice_send') $ok = self::send_invoice($id);
        elseif ($action === 'cancel_registration') $ok = self::cancel_registration($id);
        elseif ($action === 'save_agreement_draft') {
            $html = wp_kses_post(wp_unslash($_POST['agreement_html'] ?? ''));
            $ok = self::save_agreement_draft($id, $html);
        }
        elseif ($action === 'email') {
            global $wpdb;
            $r=$wpdb->get_row($wpdb->prepare("SELECT parent_email FROM ".BCS_DB::table('registrations')." WHERE id=%d",$id));
            $subject = sanitize_text_field(wp_unslash($_POST['subject'] ?? 'Wiadomość z Basketmania Camp'));
            $body = wp_kses_post(wp_unslash($_POST['message'] ?? ''));
            $ok = $r ? BCS_Mailer::send($r->parent_email,$subject,$body,['Content-Type: text/html; charset=UTF-8'],[],$id) : false;
            if ($ok) self::activity($id, 'email', 'Wysłano wiadomość e-mail', $subject);
        }
        $return_to=sanitize_key(wp_unslash($_POST['return_to']??'card'));
        $args=['page'=>'bcs-registrations','crm_done'=>$ok?1:0];
        if($return_to!=='list')$args['view']=$id;
        if ($action === 'verify_form' && isset($verify_result)) $args['form_verified_action']=1;
        if ($action === 'portal_send' && isset($invite_result)) {
            $args['portal_sent']=1;
            $args['portal_email']=!empty($invite_result['email'])?1:0;
            $args['portal_sms']=!empty($invite_result['sms'])?1:0;
        }
        $url = add_query_arg($args, admin_url('admin.php'));
        wp_safe_redirect($url); exit;
    }

    public static function activity(int $id, string $type, string $title, string $note='', bool $write_log=true): bool {
        global $wpdb;
        $ok = $wpdb->insert(BCS_DB::table('activities'), [
            'registration_id'=>$id,'activity_type'=>$type,'title'=>$title,'note'=>$note,
            'created_by'=>get_current_user_id(),'created_at'=>BCS_Utils::now(),
        ]);
        if($write_log) BCS_Utils::log('crm_'.$type, ['title'=>$title,'note'=>$note], $id, null);
        return (bool)$ok;
    }

    private static function cancel_registration(int $id): bool {
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare("SELECT status FROM ".BCS_DB::table('registrations')." WHERE id=%d", $id));
        if (!$r || $r->status === 'cancelled') return false;
        $updated = $wpdb->update(BCS_DB::table('registrations'), [
            'status' => 'cancelled',
            'updated_at' => BCS_Utils::now(),
        ], ['id' => $id]);
        if ($updated === false) return false;
        self::activity($id, 'registration_cancelled', 'Anulowano zgłoszenie', 'Zgłoszenie pozostaje w CRM i może zostać wykorzystane do przyszłego kontaktu.', false);
        BCS_Utils::log('registration_cancelled', [], $id, null);
        return true;
    }

    private static function save_agreement_draft(int $id, string $html): bool {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT r.agreement_id, a.status, a.agreement_number FROM ".BCS_DB::table('registrations')." r LEFT JOIN ".BCS_DB::table('agreements')." a ON a.id=r.agreement_id WHERE r.id=%d",
            $id
        ));
        if (!$row || empty($row->agreement_id) || $row->status !== 'draft' || trim(wp_strip_all_tags($html)) === '') return false;
        $hash = hash('sha256', $html);
        $now = BCS_Utils::now();
        $updated = $wpdb->update(BCS_DB::table('agreements'), [
            'html' => $html,
            'document_hash' => $hash,
            'version' => 'draft-edited',
        ], ['id' => (int)$row->agreement_id]);
        if ($updated === false) return false;
        $stage = 'draft';
        $table = BCS_DB::table('agreement_versions');
        $version_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE agreement_id=%d AND stage=%s", (int)$row->agreement_id, $stage));
        $data = [
            'agreement_id' => (int)$row->agreement_id,
            'registration_id' => $id,
            'stage' => $stage,
            'html' => $html,
            'document_hash' => $hash,
            'agreement_number' => (string)$row->agreement_number,
            'created_at' => $now,
        ];
        if ($version_id) $wpdb->update($table, $data, ['id' => (int)$version_id]); else $wpdb->insert($table, $data);
        $wpdb->update(BCS_DB::table('registrations'), ['updated_at' => $now], ['id' => $id]);
        self::activity($id, 'agreement_draft_edited', 'Zaktualizowano draft umowy', 'Treść umowy została zmieniona przez administratora.', false);
        BCS_Utils::log('agreement_draft_edited', ['hash' => $hash, 'stage' => $stage], $id, (int)$row->agreement_id);
        return true;
    }

    private static function send_invoice(int $id): bool {
        global $wpdb;
        $r=$wpdb->get_row($wpdb->prepare("SELECT parent_email,parent_first_name,parent_last_name FROM ".BCS_DB::table('registrations')." WHERE id=%d",$id));
        $inv=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".BCS_DB::table('invoices')." WHERE registration_id=%d ORDER BY id DESC LIMIT 1",$id));
        if(!$r || !$inv || !$inv->file_path || !is_file($inv->file_path)) return false;
        $ok=BCS_Mailer::send($r->parent_email,'Faktura '.$inv->invoice_number,'Dzień dobry,<br><br>w załączeniu przesyłamy dokument sprzedaży dotyczący Basketmania Camp.<br><br>Pozdrawiamy<br>Basketmania Camp',['Content-Type: text/html; charset=UTF-8'],[$inv->file_path],$id);
        if($ok){ global $wpdb; $wpdb->update(BCS_DB::table('registrations'),['invoice_status'=>'sent','invoice_sent_at'=>BCS_Utils::now(),'updated_at'=>BCS_Utils::now()],['id'=>$id]); self::activity($id,'invoice_sent','Wysłano fakturę',$inv->invoice_number.' → '.$r->parent_email); }
        return $ok;
    }

    private static function device_icon(string $device_type): string {
        $map = [
            'mobile' => ['dashicons-smartphone', 'Urządzenie mobilne'],
            'tablet' => ['dashicons-tablet', 'Tablet'],
            'desktop' => ['dashicons-desktop', 'Komputer stacjonarny lub laptop'],
            'unknown' => ['dashicons-editor-help', 'Nie rozpoznano urządzenia'],
        ];
        $item = $map[$device_type] ?? $map['unknown'];
        return '<span class="dashicons '.esc_attr($item[0]).' bcs-device-icon" title="'.esc_attr($item[1]).'" aria-label="'.esc_attr($item[1]).'"></span>';
    }

    private static function payment_reference(object $r): string {
        if (empty($r->payment_real_id)) return '';
        if (!empty($r->payment_paid_at)) {
            $stripe_icon = $r->payment_provider === 'stripe'
                ? '<img class="bcs-stripe-icon" src="https://stripe.com/favicon.ico" width="14" height="14" alt="Stripe" title="Płatność potwierdzona przez Stripe" loading="lazy">'
                : '';
            return '<br><span class="bcs-id bcs-payment-date">Płatność '.esc_html(BCS_Utils::format_datetime($r->payment_paid_at)).$stripe_icon.'</span>';
        }
        if($r->payment_provider==='stripe') return '<br><span class="bcs-id">Oczekuje na płatność Stripe</span>';
        return '<br><span class="bcs-id">Płatność #'.esc_html($r->payment_real_id).'</span>';
    }

    public static function page(): void {
        $view=absint($_GET['view']??0);
        if($view){self::detail($view);return;}
        global $wpdb;
        $q=sanitize_text_field(wp_unslash($_GET['s']??'')); $camp=absint($_GET['camp_id']??0); $status=sanitize_key($_GET['status']??'');
        $where=['1=1'];$args=[];
        if($camp){$where[]='r.camp_id=%d';$args[]=$camp;}
        if($status){$where[]='r.status=%s';$args[]=$status;}
        if($q){$like='%'.$wpdb->esc_like($q).'%';$where[]='(r.parent_first_name LIKE %s OR r.parent_last_name LIKE %s OR r.child_first_name LIKE %s OR r.child_last_name LIKE %s OR r.parent_email LIKE %s OR r.parent_phone LIKE %s)';array_push($args,$like,$like,$like,$like,$like,$like);}
        $action_condition = BCS_Admin::action_required_condition('r');
        $sql="SELECT r.*,c.name camp_name,c.start_date,a.agreement_number,a.status agreement_record_status,p.id payment_real_id,p.provider payment_provider,p.external_id payment_external_id,p.paid_at payment_paid_at,
            CASE WHEN (".$action_condition.") THEN 1 ELSE 0 END requires_action,
            EXISTS(SELECT 1 FROM ".BCS_DB::table('logs')." lf WHERE lf.registration_id=r.id AND lf.event_type='camp_form_verified') has_form_verified_log,
            EXISTS(SELECT 1 FROM ".BCS_DB::table('activities')." af WHERE af.registration_id=r.id AND af.activity_type IN ('form_verified','camp_form_verified')) has_form_verified_activity,
            EXISTS(SELECT 1 FROM ".BCS_DB::table('agreements')." au WHERE au.id=r.agreement_id AND au.status='accepted') has_signed_agreement,
            EXISTS(SELECT 1 FROM ".BCS_DB::table('payments')." pp WHERE pp.registration_id=r.id AND pp.status='paid') has_paid_payment,
            EXISTS(SELECT 1 FROM ".BCS_DB::table('invoices')." fi WHERE fi.registration_id=r.id AND fi.status='sent') has_sent_invoice
            FROM ".BCS_DB::table('registrations')." r JOIN ".BCS_DB::table('camps')." c ON c.id=r.camp_id LEFT JOIN ".BCS_DB::table('agreements')." a ON a.id=r.agreement_id LEFT JOIN ".BCS_DB::table('payments')." p ON p.id=r.payment_id WHERE ".implode(' AND ',$where)." ORDER BY
            CASE r.status
                WHEN 'new' THEN 10
                WHEN 'admin_confirmed' THEN 10
                WHEN 'form_complete' THEN 30
                WHEN 'draft_sent' THEN 40
                WHEN 'agreement_sent' THEN 50
                WHEN 'awaiting_bank_payment' THEN 60
                WHEN 'stripe_link_sent' THEN 70
                WHEN 'partially_paid' THEN 80
                WHEN 'paid' THEN 90
                WHEN 'cancelled' THEN 100
                ELSE 85
            END ASC,
            r.created_at DESC,
            r.id DESC";
        $rows=$args?$wpdb->get_results($wpdb->prepare($sql,...$args)):$wpdb->get_results($sql);
        $camps=$wpdb->get_results("SELECT id,name FROM ".BCS_DB::table('camps')." ORDER BY start_date DESC");
        $labels=BCS_Workflow_Engine::statuses();
        $action_count = BCS_Admin::action_required_count();
        $action_badge = $action_count > 0
            ? '<span class="bcs-action-count" title="Zgłoszenia wymagające działania administratora">'.(int)$action_count.'</span>'
            : '';
        echo '<div class="wrap bcs-admin bcs-crm"><div class="bcs-page-head"><div><h1>CRM klientów '.$action_badge.'</h1><p>Zgłoszenia, komunikacja, zadania, płatności i dokumenty w jednym miejscu.</p></div><span class="bcs-id">'.count($rows).' rekordów</span></div>';
        echo '<div class="bcs-kpis"><div class="bcs-kpi bcs-kpi-attention"><span class="dashicons dashicons-warning"></span><div><small>Wymagają akcji</small><strong>'.$action_badge.' '.(int)$action_count.'</strong></div></div><div class="bcs-kpi"><span class="dashicons dashicons-groups"></span><div><small>Aktywne zgłoszenia</small><strong>'.count(array_filter($rows,fn($r)=>$r->status!=='cancelled')).'</strong></div></div><div class="bcs-kpi"><span class="dashicons dashicons-yes-alt"></span><div><small>Opłacone</small><strong>'.count(array_filter($rows,fn($r)=>$r->status==='paid')).'</strong></div></div><div class="bcs-kpi"><span class="dashicons dashicons-edit"></span><div><small>Do uzupełnienia</small><strong>'.count(array_filter($rows,fn($r)=>$r->status!=='cancelled' && ($r->form_status??'complete')!=='complete')).'</strong></div></div></div>';
        echo '<form method="get" class="bcs-toolbar bcs-live-toolbar" data-bcs-live-filter><input type="hidden" name="page" value="bcs-registrations"><input type="search" name="s" value="'.esc_attr($q).'" placeholder="Szukaj rodzica, dziecka, telefonu…" autocomplete="off" data-bcs-filter-search><select name="camp_id" data-bcs-filter-camp><option value="0">Wszystkie turnusy</option>';foreach($camps as $c)echo '<option value="'.(int)$c->id.'" '.selected($camp,$c->id,false).'>'.esc_html($c->name).'</option>';echo '</select><select name="status" data-bcs-filter-status><option value="">Wszystkie statusy</option>';foreach($labels as $k=>$l)echo '<option value="'.$k.'" '.selected($status,$k,false).'>'.esc_html($l).'</option>';echo '</select><button type="button" class="button" data-bcs-filter-reset>Wyczyść</button><span class="bcs-live-results" data-bcs-results-count></span></form>';
        echo '<div class="bcs-crm-list-layout"><div class="bcs-table-wrap"><table class="widefat bcs-table" data-bcs-live-table><thead><tr><th><button type="button" class="bcs-sort-button" data-bcs-sort="id" data-direction="desc">#ID <span aria-hidden="true">↕</span></button></th><th><button type="button" class="bcs-sort-button" data-bcs-sort="created">Data zgłoszenia <span aria-hidden="true">↕</span></button></th><th><button type="button" class="bcs-sort-button" data-bcs-sort="client">Klient / uczestnik <span aria-hidden="true">↕</span></button></th><th><button type="button" class="bcs-sort-button" data-bcs-sort="camp">Turnus <span aria-hidden="true">↕</span></button></th><th><button type="button" class="bcs-sort-button" data-bcs-sort="stage">Etap <span aria-hidden="true">↕</span></button></th><th><button type="button" class="bcs-sort-button" data-bcs-sort="paid">Rozliczenie <span aria-hidden="true">↕</span></button></th><th>Postęp</th><th>Szybkie akcje</th><th></th></tr></thead><tbody>';
        foreach($rows as $r){
            $milestones=self::milestone_badges($r);
            $due=max(0,(float)$r->total_amount-(float)$r->paid_amount);
            $quick=self::list_quick_actions_html($r);
            $row_classes=[]; if(!empty($r->requires_action))$row_classes[]='bcs-requires-action'; if(!empty($r->has_sent_invoice) || ($r->invoice_status??'')==='sent')$row_classes[]='bcs-registration-complete'; $row_class=$row_classes?' class="'.esc_attr(implode(' ',$row_classes)).'"':'';
            $action_marker = !empty($r->requires_action) ? '<span class="bcs-row-action-marker" title="To zgłoszenie wymaga działania administratora">Wymaga akcji</span>' : '';
            $search_blob = strtolower(trim($r->parent_first_name.' '.$r->parent_last_name.' '.$r->child_first_name.' '.$r->child_last_name.' '.$r->parent_email.' '.$r->parent_phone.' '.$r->camp_name.' '.($labels[$r->status]??$r->status)));
            $data_attrs = ' data-id="'.(int)$r->id.'" data-created="'.esc_attr((string)$r->created_at).'" data-client="'.esc_attr(strtolower(trim($r->parent_last_name.' '.$r->parent_first_name.' '.$r->child_last_name.' '.$r->child_first_name))).'" data-camp="'.esc_attr(strtolower((string)$r->camp_name)).'" data-camp-id="'.(int)$r->camp_id.'" data-stage="'.esc_attr(strtolower((string)($labels[$r->status]??$r->status))).'" data-status="'.esc_attr((string)$r->status).'" data-paid="'.esc_attr((string)(float)$r->paid_amount).'" data-updated="'.esc_attr((string)$r->updated_at).'" data-requires="'.(!empty($r->requires_action)?'1':'0').'" data-search="'.esc_attr($search_blob).'"';
            $preview_id='bcs-camp-form-preview-'.(int)$r->id;
            echo '<tr'.$row_class.$data_attrs.'><td><span class="bcs-id">#'.(int)$r->id.'</span>'.self::device_icon((string)($r->device_type??'unknown')).(empty($r->form_verified_at)&&BCS_Locks::active((int)$r->id)?'<span class="dashicons dashicons-lock bcs-registration-lock-icon" title="Formularz Obozowy jest chwilowo zablokowany z powodu pracy administratora."></span>':'').$action_marker.'</td><td><strong>'.esc_html(wp_date('d.m.Y',strtotime((string)$r->created_at))).'</strong><br><small>'.esc_html(wp_date('H:i',strtotime((string)$r->created_at))).'</small></td><td><div class="bcs-client-preview-wrap"><div><strong>'.esc_html($r->parent_first_name.' '.$r->parent_last_name).'</strong><br><span>'.esc_html($r->child_first_name.' '.$r->child_last_name).'</span></div><button type="button" class="bcs-registration-preview" title="Podejrzyj Formularz Obozowy" data-preview-template="'.esc_attr($preview_id).'"><span class="dashicons dashicons-visibility"></span></button><template id="'.esc_attr($preview_id).'">'.self::camp_form_preview_html($r).'</template></div></td><td><strong>'.esc_html($r->camp_name).'</strong><br><small>'.esc_html($r->start_date).'</small></td><td data-bcs-col="status"><span class="bcs-badge '.esc_attr(self::status_class($r->status)).'">'.esc_html($labels[$r->status]??$r->status).'</span><br><small>'.esc_html($r->agreement_number?:'Bez umowy').'</small></td><td data-bcs-col="payment"><strong>'.number_format((float)$r->paid_amount,2,',',' ').' zł</strong><br><small>pozostało '.number_format($due,2,',',' ').' zł</small>'.self::payment_reference($r).'</td><td data-bcs-col="progress">'.$milestones.'</td><td data-bcs-col="actions">'.$quick.'</td><td><a class="button button-primary bcs-open-crm-icon-02024" href="'.esc_url(admin_url('admin.php?page=bcs-registrations&view='.$r->id)).'" title="Otwórz kartę CRM" aria-label="Otwórz kartę CRM"><span aria-hidden="true"></span></a></td></tr>';
        }
        if(!$rows)echo '<tr class="bcs-server-empty"><td colspan="9"><div class="bcs-empty">Brak wyników.</div></td></tr>';
        echo '<tr class="bcs-live-empty" hidden><td colspan="9"><div class="bcs-empty">Brak zgłoszeń spełniających wybrane kryteria.</div></td></tr>';
        echo '</tbody></table></div><aside class="bcs-status-legend bcs-panel"><h2>Legenda etapów</h2>'.self::status_legend().'</aside></div>';
        echo '<div id="bcs-contact-modal" class="bcs-contact-modal" hidden><div class="bcs-contact-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="bcs-contact-modal-title"><button type="button" class="bcs-contact-modal__close" aria-label="Zamknij">×</button><div class="bcs-panel-head"><div><h2 id="bcs-contact-modal-title">Wiadomość e-mail</h2><p>Wysyłka przez <strong>'.esc_html(BCS_Mailer::transport_label()).'</strong> z konta skonfigurowanego w Ustawieniach.</p></div><span class="dashicons dashicons-email-alt"></span></div><form method="post" class="bcs-email-compose"><input type="hidden" name="registration_id" value=""><input type="hidden" name="bcs_crm_action" value="email"><input type="hidden" name="return_to" value="list"><input type="hidden" name="_wpnonce" value=""><div class="bcs-email-recipient"><span>Do</span><strong data-bcs-contact-recipient></strong></div><label><span>Temat wiadomości</span><input type="text" name="subject" value="Basketmania Camp: Informacja dotycząca zgłoszenia" required></label><label><span>Treść wiadomości</span><textarea class="bcs-rich-compose" name="message" rows="10" required></textarea></label><button class="button button-primary bcs-email-send"><span class="dashicons dashicons-email-alt"></span> Wyślij e-mail</button></form></div></div></div><div id="bcs-registration-preview-modal" class="bcs-contact-modal" hidden><div class="bcs-contact-modal__dialog bcs-registration-preview-dialog" role="dialog" aria-modal="true"><button type="button" class="bcs-registration-preview-close" aria-label="Zamknij">×</button><div class="bcs-panel-head"><div><h2>Formularz Obozowy</h2><p>Komplet danych zapisanych przy tym zgłoszeniu.</p></div><span class="dashicons dashicons-visibility"></span></div><div class="bcs-form-preview-sections" data-bcs-registration-preview-content></div></div></div>';
    }

    private static function camp_form_preview_html(object $r): string {
        $sections = [
            'Rodzic' => [
                'parent_first_name'=>'Imię rodzica','parent_last_name'=>'Nazwisko rodzica','parent_email'=>'E-mail','parent_phone'=>'Telefon',
                'parent_postal_code'=>'Kod pocztowy','parent_city'=>'Miejscowość','parent_street'=>'Ulica','parent_house_number'=>'Nr domu / lokalu',
            ],
            'Uczestnik obozu' => [
                'child_first_name'=>'Imię uczestnika','child_last_name'=>'Nazwisko uczestnika','child_birth_date'=>'Data urodzenia',
                'child_pesel'=>'PESEL','child_height'=>'Wzrost (cm)','shirt_size'=>'Rozmiar stroju','child_club'=>'Klub',
                'medical_notes'=>'Alergie, leki i informacje zdrowotne','dietary_notes'=>'Dieta i żywienie',
            ],
            'Informacje dotyczące obozu' => [
                'stay_contact'=>'Dane kontaktowe podczas pobytu','authorized_pickup'=>'Osoby upoważnione do odbioru',
                'camp_notes'=>'Dodatkowe informacje dla organizatora',
            ],
        ];
        $html='';
        foreach($sections as $heading=>$fields){
            $html.='<section class="bcs-form-preview-section"><h3>'.esc_html($heading).'</h3><div class="bcs-detail-grid bcs-form-preview-grid">';
            foreach($fields as $key=>$label){
                $value=property_exists($r,$key)?trim((string)$r->{$key}):'';
                if($key==='stay_contact' && $value==='') $value=trim((string)($r->parent_first_name??'').' '.(string)($r->parent_last_name??'').' — '.(string)($r->parent_phone??''));
                if($value==='') $value='—';
                $wide=in_array($key,['medical_notes','dietary_notes','stay_contact','authorized_pickup','camp_notes'],true)?' bcs-form-preview-wide':'';
                $html.='<div class="bcs-preview-row'.$wide.'"><b>'.esc_html($label).'</b><span>'.nl2br(esc_html($value)).'</span></div>';
            }
            $html.='</div></section>';
        }
        return $html;
    }

    private static function detail(int $id): void {
        global $wpdb;
        $r=$wpdb->get_row($wpdb->prepare("SELECT r.*,c.name camp_name,c.start_date,c.end_date,c.location,a.agreement_number,a.accepted_at,a.status agreement_record_status,a.html agreement_html,a.document_hash agreement_hash,p.id payment_real_id,p.provider payment_provider,p.external_id payment_external_id,p.paid_at payment_paid_at,i.id invoice_real_id,i.invoice_number,i.file_path invoice_file_path FROM ".BCS_DB::table('registrations')." r JOIN ".BCS_DB::table('camps')." c ON c.id=r.camp_id LEFT JOIN ".BCS_DB::table('agreements')." a ON a.id=r.agreement_id LEFT JOIN ".BCS_DB::table('payments')." p ON p.id=r.payment_id LEFT JOIN ".BCS_DB::table('invoices')." i ON i.id=(SELECT i2.id FROM ".BCS_DB::table('invoices')." i2 WHERE i2.registration_id=r.id ORDER BY i2.id DESC LIMIT 1) WHERE r.id=%d",$id));
        if(!$r){echo '<div class="wrap"><h1>Nie znaleziono zgłoszenia.</h1></div>';return;}
        if(empty($r->form_verified_at)) BCS_Locks::touch($id, get_current_user_id());
        $acts=$wpdb->get_results($wpdb->prepare("SELECT ac.*,u.display_name FROM ".BCS_DB::table('activities')." ac LEFT JOIN {$wpdb->users} u ON u.ID=ac.created_by WHERE ac.registration_id=%d AND ac.activity_type IN ('phone','note','task','email','invoice_sent') ORDER BY ac.created_at ASC, ac.id ASC",$id));
        $versions=$wpdb->get_results($wpdb->prepare("SELECT * FROM ".BCS_DB::table('agreement_versions')." WHERE registration_id=%d ORDER BY created_at ASC",$id));
        $logs=$wpdb->get_results($wpdb->prepare("SELECT * FROM ".BCS_DB::table('logs')." WHERE registration_id=%d ORDER BY created_at ASC, id ASC",$id));
        $portal=get_page_by_path('panel-rodzica');$portal_url=add_query_arg(['token'=>$r->public_token,'bcs_admin_preview'=>1],$portal?get_permalink($portal):home_url('/panel-rodzica/'));
        if(isset($_GET['communication_action'])) {
            $comm=get_transient('bcs_workflow_comm_'.get_current_user_id().'_'.$id);
            delete_transient('bcs_workflow_comm_'.get_current_user_id().'_'.$id);
            if(is_array($comm)){
                $email_state=$comm['email'];$sms_state=$comm['sms'];$email_ok=($email_state===true);$sms_ok=($sms_state===true);$any_failed=($email_state===false||$sms_state===false);
                $class=$any_failed?'notice-warning':'notice-success';
                $email_label=$email_state===null?'nie wysyłano':($email_ok?'przekazany przez '.$comm['email_transport']:'błąd');
                $sms_label=$sms_state===null?'nie wysyłano':($sms_ok?'wysłany przez '.$comm['sms_provider']:'błąd');
                echo '<div class="notice '.esc_attr($class).' is-dismissible"><p><strong>Wynik wysyłki powiadomienia:</strong> e-mail — '.esc_html($email_label).'; SMS — '.esc_html($sms_label).'.</p>';
                if(!$email_ok && !empty($comm['email_error'])) echo '<p><strong>Błąd e-mail:</strong> '.esc_html($comm['email_error']).'</p>';
                if(!$sms_ok && !empty($comm['sms_error'])) echo '<p><strong>Błąd SMS:</strong> '.esc_html($comm['sms_error']).'</p>';
                echo '</div>';
            }
        } elseif(isset($_GET['portal_sent'])) {
            $result=get_transient('bcs_portal_send_'.get_current_user_id().'_'.$id);
            delete_transient('bcs_portal_send_'.get_current_user_id().'_'.$id);
            if(!is_array($result)) $result=[];
            $email_ok=!empty($result['email']) || !empty($_GET['portal_email']);
            $sms_ok=!empty($result['sms']) || !empty($_GET['portal_sms']);
            $class=($email_ok && $sms_ok)?'notice-success':'notice-warning';
            $message='Wykonano ponowne wysłanie formularza. E-mail: '.($email_ok?'przekazany do poczty hostingu':'błąd wysyłki').'. SMS: '.($sms_ok?'wysłany przez aktywną bramkę SMS':'błąd wysyłki').'.';
            echo '<div class="notice '.esc_attr($class).' is-dismissible"><p><strong>'.esc_html($message).'</strong></p>';
            if(!$email_ok && !empty($result['email_error'])) echo '<p><strong>Szczegóły e-mail:</strong> '.esc_html($result['email_error']).'</p>';
            if(!$sms_ok && !empty($result['sms_error'])) echo '<p><strong>Szczegóły bramki SMS:</strong> '.esc_html($result['sms_error']).'</p>';
            echo '</div>';
        } elseif(isset($_GET['form_verified_action'])) {
            $verify_result=get_transient('bcs_form_verify_'.get_current_user_id().'_'.$id);
            delete_transient('bcs_form_verify_'.get_current_user_id().'_'.$id);
            $email_ok=!empty($verify_result['email']);
            $draft_ok=!empty($verify_result['draft']);
            echo '<div class="notice notice-success is-dismissible"><p><strong>Formularz obozowy został potwierdzony przez Organizatora.</strong></p>';
            echo '<p>E-mail do rodzica: '.($email_ok?'przekazany do wysyłki':'nie został wysłany — sprawdź logi poczty').'. Draft umowy PDF: '.($draft_ok?'wygenerowany':'zostanie wygenerowany przy pobraniu dokumentu').'.</p></div>';
        } elseif(isset($_GET['crm_done'])) {
            echo '<div class="notice '.(!empty($_GET['crm_done'])?'notice-success':'notice-error').' is-dismissible"><p>'.(!empty($_GET['crm_done'])?'Działanie zostało wykonane.':'Nie udało się wykonać działania. Sprawdź ustawienia i logi komunikacji.').'</p></div>';
        }
        $age_label = '—';
        if (!empty($r->child_birth_date)) {
            try {
                $birth = new DateTimeImmutable((string)$r->child_birth_date, BCS_Utils::timezone());
                $today = new DateTimeImmutable('today', BCS_Utils::timezone());
                if ($birth <= $today) $age_label = $birth->diff($today)->y.' lat';
            } catch (Throwable $e) {}
        }
        echo '<div class="wrap bcs-admin bcs-crm"><div class="bcs-page-head"><div><a class="bcs-back" href="'.esc_url(admin_url('admin.php?page=bcs-registrations')).'">← CRM klientów</a><h1>Karta zgłoszenia <span class="bcs-id">#'.$id.'</span></h1><div class="bcs-registration-identity"><div><span>Klient</span><strong>'.esc_html(trim($r->parent_first_name.' '.$r->parent_last_name)).'</strong></div><div><span>Uczestnik</span><strong>'.esc_html(trim($r->child_first_name.' '.$r->child_last_name)).'</strong></div><div><span>Wiek</span><strong>'.esc_html($age_label).'</strong></div><div><span>Turnus</span><strong>'.esc_html((string)$r->camp_name).'</strong></div></div>'.(empty($r->form_verified_at)?'<div class="bcs-lock-note"><span class="dashicons dashicons-lock"></span> Edycja Formularza Obozowego w Panelu Rodzica jest zablokowana przez '.esc_html((string)(BCS_Locks::ttl()/MINUTE_IN_SECONDS)).' min od ostatniego otwarcia lub odświeżenia tej karty.</div>':'').'</div><div class="bcs-actions">'.'<a class="button" target="_blank" href="'.esc_url($portal_url).'"><span class="dashicons dashicons-visibility"></span> Podgląd panelu rodzica</a>'.'<a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=bcs-registrations&edit='.$id)).'">Edytuj dane</a></div></div>';
        $form_download=(($r->form_status??'')==='complete')?'<a class="bcs-summary-download" href="'.esc_url(BCS_Document_Engine::download_url((int)$r->id,'form')).'" title="Pobierz formularz obozowy PDF" aria-label="Pobierz formularz obozowy PDF"><span class="dashicons dashicons-download"></span></a>':'';
        $agreement_download=!empty($r->agreement_number)?'<a class="bcs-summary-download" href="'.esc_url(BCS_Document_Engine::download_url((int)$r->id,($r->agreement_record_status==='accepted'?'agreement_signed':'agreement_current'))).'" title="Pobierz umowę PDF" aria-label="Pobierz umowę PDF"><span class="dashicons dashicons-download"></span></a>':'';
        $invoice_download=!empty($r->invoice_real_id)?'<a class="bcs-summary-download" href="'.esc_url(BCS_Document_Engine::download_url((int)$r->id,'invoice')).'" title="Pobierz fakturę" aria-label="Pobierz fakturę"><span class="dashicons dashicons-download"></span></a>':'';
        $form_check=!empty($r->form_verified_at)?'<span class="dashicons dashicons-yes-alt bcs-summary-check-icon" aria-label="Wykonano"></span> ':'';$agreement_check=($r->agreement_record_status==='accepted')?'<span class="dashicons dashicons-yes-alt bcs-summary-check-icon" aria-label="Wykonano"></span> ':'';$payment_check=((float)$r->total_amount>0&&(float)$r->paid_amount>=(float)$r->total_amount)?'<span class="dashicons dashicons-yes-alt bcs-summary-check-icon" aria-label="Wykonano"></span> ':'';$invoice_check=!empty($r->invoice_real_id)?'<span class="dashicons dashicons-yes-alt bcs-summary-check-icon" aria-label="Wykonano"></span> ':'';
        echo '<div class="bcs-crm-layout"><main><section class="bcs-panel"><h2>Podsumowanie</h2><div class="bcs-stat-grid"><div><span>Status</span><strong><span class="bcs-badge '.esc_attr(self::status_class($r->status)).'">'.esc_html(BCS_Workflow_Engine::statuses()[$r->status]??$r->status).'</span></strong></div><div><span>Formularz obozowy</span><strong class="bcs-summary-value">'.$form_check.esc_html(!empty($r->form_verified_at)?'Zweryfikowany':(($r->form_status??'')==='complete'?'Oczekuje na weryfikację':'Do uzupełnienia')).$form_download.'</strong></div><div><span>Umowa</span><strong class="bcs-summary-value">'.$agreement_check.esc_html($r->agreement_number?:'—').$agreement_download.'</strong></div><div><span>Płatność</span><strong>'.$payment_check.number_format((float)$r->paid_amount,2,',',' ').' / '.number_format((float)$r->total_amount,2,',',' ').' zł</strong></div>'.(!empty($r->invoice_real_id)?'<div><span>Faktura</span><strong class="bcs-summary-value">'.$invoice_check.esc_html($r->invoice_number?:'Wygenerowana').$invoice_download.'</strong></div>':'').'</div><p><strong>Turnus:</strong> '.esc_html($r->camp_name).' · '.esc_html($r->start_date.' – '.$r->end_date).' · '.esc_html($r->location).'</p><p><strong>Kontakt:</strong> <a href="mailto:'.esc_attr($r->parent_email).'">'.esc_html($r->parent_email).'</a> · <a href="tel:'.esc_attr($r->parent_phone).'">'.esc_html($r->parent_phone).'</a></p></section>';
        echo self::important_notes_panel($r);
        echo self::camp_form_accordion($r);
        if(($r->form_status??'')==='complete' && empty($r->form_verified_at)) echo '<section class="bcs-panel bcs-form-verification"><h2>Weryfikacja formularza obozowego</h2><p>Formularz został uzupełniony przez rodzica i oczekuje na sprawdzenie. Po potwierdzeniu system wyśle e-mail z informacją o akceptacji oraz draftem umowy w PDF.</p><form method="post">'.wp_nonce_field('bcs_crm_'.$id,'_wpnonce',true,false).'<input type="hidden" name="registration_id" value="'.$id.'"><button class="button button-primary" name="bcs_crm_action" value="verify_form"><span class="dashicons dashicons-yes-alt"></span> Potwierdź poprawność formularza obozowego</button></form></section>';
        echo self::agreement_accordion($r,$versions);
        echo self::documents_panel($r,$versions);
        echo self::mail_correspondence_panel($id);
        echo '<section class="bcs-panel bcs-accordion-panel bcs-history-panel"><details><summary><span><span class="dashicons dashicons-backup"></span><strong>Historia klienta</strong></span><span class="bcs-accordion-hint">'.count($logs).' zdarzeń w logach</span></summary><div class="bcs-accordion-content"><div class="bcs-timeline">';
        $events=[];$has_created=false;$logged_activity_types=[];
        foreach($logs as $l){
            $data=json_decode((string)$l->event_data,true);
            if(!is_array($data))$data=[];
            if($l->event_type==='registration_created')$has_created=true;
            if(str_starts_with((string)$l->event_type,'crm_'))$logged_activity_types[substr((string)$l->event_type,4)]=true;
            $actor_type=BCS_Utils::infer_actor_type((string)$l->event_type,$data);
            $author=trim((string)($data['_actor_display_name']??''));
            if($author==='')$author=BCS_Utils::actor_label($actor_type);
            $events[]=[
                'date'=>(string)$l->created_at,
                'order'=>(int)$l->id,
                'title'=>self::log_label((string)$l->event_type),
                'note'=>self::log_note($data),
                'author'=>$author,
                'type'=>(string)$l->event_type,
                'source'=>'log',
            ];
        }
        if(!$has_created)$events[]=['date'=>(string)$r->created_at,'order'=>0,'title'=>'Utworzono zgłoszenie','note'=>'','author'=>'System','type'=>'registration_created','source'=>'fallback'];
        foreach($acts as $a){
            if(!empty($logged_activity_types[(string)$a->activity_type]))continue;
            $events[]=['date'=>(string)$a->created_at,'order'=>(int)$a->id,'title'=>(string)$a->title,'note'=>(string)$a->note,'author'=>$a->display_name?:'Administrator','type'=>'activity_'.(string)$a->activity_type,'source'=>'activity'];
        }
        usort($events,static function(array $a,array $b):int{
            $date=strcmp($a['date'],$b['date']);
            if($date!==0)return $date;
            $source=strcmp($a['source'],$b['source']);
            return $source!==0?$source:($a['order']<=>$b['order']);
        });
        foreach($events as $e)echo '<div class="bcs-timeline-item" data-event-type="'.esc_attr($e['type']).'"><span class="bcs-timeline-dot"></span><div><strong>'.esc_html($e['title']).'</strong><small>'.esc_html(BCS_Utils::format_datetime($e['date']).' · '.$e['author']).'</small>'.($e['note']?'<p>'.nl2br(esc_html($e['note'])).'</p>':'').'</div></div>';
        if(!$events)echo '<p class="bcs-muted">Brak zdarzeń powiązanych z tym zgłoszeniem.</p>';
        echo '</div></div></details></section></main><aside>';
        echo '<section class="bcs-panel bcs-quick-actions"><h2>Szybkie czynności</h2>';
        self::action_form($id,'phone','Wykonano telefon','Notatka z rozmowy…');
        self::action_form($id,'note','Dodaj notatkę','Treść notatki…');
        self::action_form($id,'task','Dodaj zadanie','Co trzeba wykonać i do kiedy…');
        echo '<div class="bcs-crm-buttons">';
        echo '<a class="button bcs-action-available bcs-parent-panel-button" target="_blank" rel="noopener noreferrer" href="'.esc_url($portal_url).'"><span class="dashicons dashicons-visibility"></span> Zobacz panel rodzica</a>';
        if($r->status === 'cancelled') {
            echo '<span class="bcs-action-cancelled"><span class="dashicons dashicons-dismiss"></span> Zgłoszenie anulowane — nie jest wliczane do liczby uczestników ani podsumowań finansowych.</span>';
        } else {
            if(!empty($r->admin_confirmed_at) && empty($r->form_verified_at)) self::simple_action($id,'portal_send','Wyślij ponownie e-mail i SMS z formularzem','button bcs-action-available');
            elseif(!empty($r->form_verified_at)) echo '<span class="bcs-action-done"><span class="dashicons dashicons-yes-alt"></span> Formularz wysłany i zweryfikowany — wykonano</span>';
            if(!empty($r->admin_confirmed_at)) echo '<span class="bcs-action-done"><span class="dashicons dashicons-yes-alt"></span> Rejestracja potwierdzona - wykonano</span>'; else echo self::workflow_button($id,'confirm_registration','Potwierdź rejestrację');
            $is_paid=(float)$r->total_amount>0 && (float)$r->paid_amount >= (float)$r->total_amount;
            $form_verified=!empty($r->form_verified_at);
            $agreement_accepted=($r->agreement_status==='accepted' || $r->agreement_record_status==='accepted');
            $agreement_pending=($r->agreement_status==='pending' || $r->agreement_record_status==='pending');
            $agreement_action_label=$agreement_pending?'Przypomnij o podpisaniu umowy':'Wyślij umowę DO PODPISU';
            echo self::conditional_workflow_button($id,'send_agreement',$agreement_action_label,$form_verified && !$agreement_accepted,$agreement_accepted?'Umowa podpisana — wykonano':'Dostępne po zaakceptowaniu formularza');
            if ($is_paid) {
                echo '<span class="bcs-action-done bcs-payment-completed"><span class="dashicons dashicons-yes-alt"></span> Płatność została zaksięgowana — wykonano</span>';
            } else {
                echo $form_verified
                    ? '<form method="post" class="bcs-stripe-link-action-02014"><input type="hidden" name="registration_id" value="'.$id.'"><input type="hidden" name="nonce" value="'.esc_attr(wp_create_nonce('bcs_send_stripe_link_02014_'.$id)).'"><button type="submit" class="button bcs-action-available">Wyślij link Stripe</button></form>'
                    : '<span class="button disabled bcs-button-disabled bcs-action-unavailable" aria-disabled="true">Dostępne po zaakceptowaniu formularza obozowego</span>';
                echo '<div class="bcs-payment-action-row">';
                echo self::conditional_workflow_button($id,'mark_bank_paid','Zaksięguj wpłatę',$agreement_accepted,'Dostępne po podpisaniu umowy SMS-em');
                echo $agreement_accepted
                    ? self::workflow_button($id,'remind_payment','Przypomnij o płatności (EMAIL + SMS)','bcs-action-reminder')
                    : '<span class="button disabled bcs-button-disabled bcs-action-unavailable" aria-disabled="true">Przypomnienie dostępne po podpisaniu umowy SMS-em</span>';
                echo '</div>';
            }
        }
        echo '</div>';
        if($r->status !== 'cancelled') {
            $invoice_available=BCS_Workflow_Engine::invoice_available($id);
            if(!empty($r->invoice_real_id) || in_array((string)$r->invoice_status,['generated','sent'],true)) {
                echo '<span class="bcs-action-done bcs-invoice-completed"><span class="dashicons dashicons-yes-alt"></span> Faktura wygenerowana — wykonano</span>';
                if($r->invoice_status==='generated') self::simple_action($id,'invoice_send','Wyślij fakturę','button bcs-action-reminder');
            } elseif($invoice_available) self::simple_action($id,'invoice_generate','Wygeneruj fakturę','button bcs-action-available');
            else { $invoice_hint=BCS_Workflow_Engine::test_mode_enabled()?'Faktura dostępna po podpisaniu umowy i pełnej płatności':'Faktura dostępna po podpisaniu umowy, pełnej płatności i od 1 stycznia roku turnusu'; echo '<span class="button disabled bcs-button-disabled" aria-disabled="true">'.esc_html($invoice_hint).'</span>'; }
            echo '<form method="post" class="bcs-crm-action bcs-cancel-action" data-confirm="Anulować to zgłoszenie? Rekord pozostanie w CRM, ale nie będzie wliczany do liczby uczestników ani podsumowań finansowych.">';
            wp_nonce_field('bcs_crm_'.$id);
            echo '<input type="hidden" name="registration_id" value="'.$id.'"><button class="button bcs-button-danger" name="bcs_crm_action" value="cancel_registration"><span class="dashicons dashicons-dismiss"></span> Anuluj zgłoszenie</button></form>';
        }
        echo '</section><section class="bcs-panel bcs-email-panel"><div class="bcs-panel-head"><div><h2>Wiadomość e-mail</h2><p>Wiadomość zostanie wysłana przez <strong>'.esc_html(BCS_Mailer::transport_label()).'</strong> z konta skonfigurowanego w Ustawieniach.</p></div><span class="dashicons dashicons-email-alt"></span></div><form method="post" class="bcs-email-compose">';wp_nonce_field('bcs_crm_'.$id);echo '<input type="hidden" name="registration_id" value="'.$id.'"><input type="hidden" name="bcs_crm_action" value="email"><div class="bcs-email-recipient"><span>Do</span><strong>'.esc_html($r->parent_email).'</strong></div><label><span>Temat wiadomości</span><input type="text" name="subject" value="Basketmania Camp: Informacja dotycząca zgłoszenia" required></label><label><span>Treść wiadomości</span><textarea class="bcs-rich-compose" name="message" rows="10" placeholder="Wpisz treść wiadomości…" required></textarea></label><button class="button button-primary bcs-email-send"><span class="dashicons dashicons-email-alt"></span> Wyślij e-mail</button></form></section></aside></div></div>';
    }


    private static function conditional_workflow_button(int $id,string $action,string $label,bool $enabled,string $disabled_label): string {
        if($enabled) return self::workflow_button($id,$action,$label);
        $completed = str_contains(strtolower($disabled_label), 'wykonano');
        if ($completed) {
            return '<span class="bcs-action-done bcs-workflow-completed" aria-disabled="true"><span class="dashicons dashicons-yes-alt"></span> '.esc_html($disabled_label).'</span>';
        }
        return '<span class="button disabled bcs-button-disabled bcs-action-unavailable" aria-disabled="true">'.esc_html($disabled_label).'</span>';
    }


    private static function important_notes_panel(object $r): string {
        $items=[];
        $diet=trim((string)($r->dietary_notes??''));
        $medical=trim((string)($r->medical_notes??''));
        $notes=trim((string)($r->camp_notes??''));
        $is_real=static fn(string $v):bool => $v!=='' && !in_array(mb_strtolower($v),['brak','nie dotyczy','—','-'],true);
        if($is_real($diet) || $is_real($medical)){
            $value=trim(implode("\n\n",array_filter([$is_real($diet)?"Dieta / alergie:\n".$diet:'',$is_real($medical)?"Potrzeby zdrowotne:\n".$medical:''])));
            $items[]=['Alergie / specjalne potrzeby','dashicons-warning',$value];
        }
        if($is_real($notes)) $items[]=['Dodatkowe informacje dla organizatora','dashicons-info-outline',$notes];
        if(!$items) return '';
        $html='<section class="bcs-panel bcs-important-notes"><h2>Ważne informacje</h2><div class="bcs-important-notes-grid">';
        foreach($items as [$title,$icon,$value]){
            $html.='<article class="bcs-important-note"><span class="dashicons '.esc_attr($icon).'"></span><div><strong>'.esc_html($title).'</strong><p>'.esc_html(wp_trim_words($value,18)).'</p><button type="button" class="button bcs-data-preview" data-title="'.esc_attr($title).'" data-content="'.esc_attr($value).'"><span class="dashicons dashicons-visibility"></span><span>Podgląd</span></button></div></article>';
        }
        return $html.'</div></section><div id="bcs-data-preview-modal" class="bcs-contact-modal" hidden><div class="bcs-contact-modal__dialog" role="dialog" aria-modal="true"><button type="button" class="bcs-data-preview-close bcs-contact-modal__close" aria-label="Zamknij">×</button><div class="bcs-panel-head"><div><h2 data-bcs-data-preview-title>Podgląd</h2><p>Informacje przekazane przez rodzica w Formularzu Obozowym.</p></div><span class="dashicons dashicons-visibility"></span></div><div class="bcs-data-preview-content" data-bcs-data-preview-content></div></div></div>';
    }

    private static function camp_form_accordion(object $r): string {
        $rows=[
            ['Opiekun',$r->parent_first_name.' '.$r->parent_last_name],['E-mail',$r->parent_email],['Telefon',$r->parent_phone],['Kod pocztowy',$r->parent_postal_code],['Miejscowość',$r->parent_city],['Ulica',$r->parent_street],['Nr domu / lokalu',$r->parent_house_number],
            ['Uczestnik',$r->child_first_name.' '.$r->child_last_name],['Data urodzenia',$r->child_birth_date],['PESEL',$r->child_pesel],['Wzrost',$r->child_height?((int)$r->child_height.' cm'):''],['Rozmiar stroju',$r->shirt_size],['Klub',$r->child_club],
            ['Uwagi zdrowotne',$r->medical_notes],['Dieta i żywienie',$r->dietary_notes],['Kontakt podczas pobytu',$r->stay_contact],['Osoby upoważnione do odbioru',$r->authorized_pickup],['Dodatkowe informacje dla organizatora',$r->camp_notes],['Turnus',$r->camp_name],['Termin',$r->start_date.' – '.$r->end_date],['Miejsce',$r->location]
        ];
        $verified=!empty($r->form_verified_at);
        $status='<span class="bcs-accordion-statuses">'.($verified?'<button type="button" class="button bcs-form-confirmed" disabled><span class="dashicons dashicons-yes-alt"></span> Dane potwierdzone przez Organizatora</button>':'').'<span class="bcs-accordion-hint">Rozwiń pełne dane</span></span>';
        $html='<section class="bcs-panel bcs-accordion-panel"><details><summary><span><span class="dashicons dashicons-clipboard"></span><strong>Dane z formularza zgłoszeniowego – obozowego</strong></span>'.$status.'</summary><div class="bcs-accordion-content"><div class="bcs-detail-grid bcs-form-preview-grid">';
        foreach($rows as [$label,$value]){$display=trim((string)$value)!==''?(string)$value:'—';$wide=in_array($label,['Uwagi zdrowotne','Dieta i żywienie'],true)?' bcs-detail-wide':'';$html.='<div class="bcs-detail-item'.$wide.'"><span>'.esc_html($label).'</span><strong>'.nl2br(esc_html($display)).'</strong></div>';}
        return $html.'</div></div></details></section>';
    }

    private static function agreement_accordion(object $r, array $versions): string {
        $by=[];foreach($versions as $v)$by[$v->stage]=$v;
        if(empty($r->form_verified_at)) unset($by['draft']);
        if(empty($by) && !empty($r->agreement_html) && !empty($r->form_verified_at)){
            $stage=$r->agreement_record_status==='accepted'?'signed':($r->agreement_record_status==='draft'?'draft':'sent');
            $by[$stage]=(object)['html'=>$r->agreement_html,'agreement_number'=>$r->agreement_number,'document_hash'=>$r->agreement_hash,'created_at'=>$r->accepted_at?:$r->updated_at];
        }
        $stages=[
            'draft'=>['Draft umowy','Wersja robocza przygotowana po zatwierdzeniu formularza obozowego.','dashicons-media-text'],
            'sent'=>['Umowa wysłana do podpisania','Niezmienna wersja przekazana rodzicowi do akceptacji kodem SMS.','dashicons-email-alt'],
            'signed'=>['Umowa podpisana – widok finalny','Finalny dokument wraz z potwierdzeniem zawarcia umowy i skrótem dokumentu.','dashicons-yes-alt']
        ];
        $html='<section class="bcs-panel bcs-accordion-panel bcs-agreements-preview"><details><summary><span><span class="dashicons dashicons-media-document"></span><strong>Podgląd umowy</strong></span><span class="bcs-accordion-hint">Rozwiń dokumenty</span></summary><div class="bcs-accordion-content"><p class="bcs-muted">Rozwijaj kolejne wersje dokumentu zapisane na poszczególnych etapach procesu.</p>';
        foreach($stages as $key=>$meta){$v=$by[$key]??null;$available=(bool)$v;$html.='<details class="bcs-document-stage '.($available?'is-ready':'is-pending').'"><summary><span><span class="dashicons '.esc_attr($meta[2]).'"></span><span><strong>'.esc_html($meta[0]).'</strong><small>'.esc_html($meta[1]).'</small></span></span><span class="bcs-stage-status">'.($available?'Dostępna':'Jeszcze niedostępna').'</span></summary>';
            if($available){$html.='<div class="bcs-document-meta"><span>Numer: <strong>'.esc_html($v->agreement_number).'</strong></span><span>Zapisano: <strong>'.esc_html(BCS_Utils::format_datetime($v->created_at)).'</strong></span><span>SHA-256: <code>'.esc_html($v->document_hash).'</code></span></div><p><a class="button bcs-action-available" href="'.esc_url(BCS_Document_Engine::download_url((int)$r->id,'agreement_'.$key)).'">Pobierz tę wersję PDF</a></p>';
                $can_edit=($key==='draft' && $r->agreement_record_status==='draft');
                if($can_edit){$html.='<form method="post" class="bcs-agreement-editor">'.wp_nonce_field('bcs_crm_'.(int)$r->id,'_wpnonce',true,false).'<input type="hidden" name="registration_id" value="'.(int)$r->id.'"><input type="hidden" name="bcs_crm_action" value="save_agreement_draft"><label><span>Edytuj treść draftu umowy</span><textarea name="agreement_html" rows="18">'.esc_textarea($v->html).'</textarea></label><button class="button bcs-action-available"><span class="dashicons dashicons-saved"></span> Zapisz zmiany w drafcie</button><p class="bcs-muted">Edycja jest dostępna wyłącznie przed wysłaniem umowy do rodzica. Wysłanie blokuje treść, aby rodzic podpisał dokładnie zatwierdzony draft.</p></form>';}
                $html.='<div class="bcs-document-preview">'.wp_kses_post($v->html).'</div>';}
            else{$html.='<div class="bcs-document-empty">Ta wersja pojawi się automatycznie po osiągnięciu odpowiedniego etapu obsługi zgłoszenia.</div>';}
            $html.='</details>';}
        return $html.'</div></details></section>';
    }

    private static function documents_panel(object $r,array $versions): string {
        $form_ready=($r->form_status??'')==='complete'&&!empty($r->form_verified_at);$agreement_ready=!empty($r->form_verified_at)&&!empty($r->agreement_id);$paid=(float)$r->total_amount>0&&(float)$r->paid_amount>=(float)$r->total_amount;$complete=$form_ready&&$r->agreement_status==='accepted'&&$paid&&$r->invoice_status==='sent';
        $html='<section class="bcs-panel bcs-accordion-panel bcs-pdf-panel"><details><summary><span><span class="dashicons dashicons-pdf"></span><strong>Dokumenty PDF</strong></span><span class="bcs-accordion-hint">Rozwiń dokumenty</span></summary><div class="bcs-accordion-content"><div class="bcs-document-actions">';
        if($form_ready)$html.='<a class="button button-primary" href="'.esc_url(BCS_Document_Engine::download_url((int)$r->id,'form')).'">Pobierz formularz obozowy PDF</a>';else$html.='<span class="bcs-muted">Formularz PDF będzie dostępny po jego uzupełnieniu.</span>';
        if($agreement_ready)$html.='<a class="button" href="'.esc_url(BCS_Document_Engine::download_url((int)$r->id,$r->agreement_status==='accepted'?'agreement_signed':'agreement_current')).'">Pobierz aktualną umowę PDF</a>';
        if($complete)$html.='<a class="button button-primary bcs-complete-download" href="'.esc_url(BCS_Document_Engine::download_url((int)$r->id,'complete')).'"><span class="dashicons dashicons-download"></span> Pobierz komplet dokumentów PDF</a>';
        else$html.='<p class="bcs-muted bcs-full">Komplet PDF pojawi się po: uzupełnieniu formularza, podpisaniu umowy, pełnej płatności oraz wygenerowaniu i wysłaniu faktury.</p>';
        return $html.'</div></div></details></section>';
    }


    private static function mail_correspondence_panel(int $registration_id): string {
        global $wpdb;
        $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM ".BCS_DB::table('mail_messages')." WHERE registration_id=%d ORDER BY received_at ASC, id ASC",$registration_id));
        $html='<section class="bcs-panel bcs-accordion-panel"><details><summary><span><span class="dashicons dashicons-email-alt"></span><strong>Korespondencja e-mail</strong></span><span class="bcs-accordion-hint">'.count($rows).' wiadomości</span></summary><div class="bcs-accordion-content">';
        if(!$rows) return $html.'<p class="bcs-muted">Brak zsynchronizowanej korespondencji dla tego zgłoszenia.</p><p><a class="button" href="'.esc_url(admin_url('admin.php?page=bcs-mailbox')).'">Otwórz moduł Poczta</a></p></div></details></section>';
        $html.='<div class="bcs-mail-thread">';
        foreach($rows as $m){
            $plain=BCS_Mailbox::content_text($m);
            $body=BCS_Mailbox::message_preview($m);
            $html.='<article class="bcs-mail-thread-item '.($m->direction==='inbound'?'is-inbound':'is-outbound').'"><header><strong>'.esc_html($m->direction==='inbound'?'Odebrano od rodzica':'Wysłano do rodzica').'</strong><span>'.esc_html(BCS_Utils::format_datetime($m->received_at)).'</span></header><h4>'.esc_html($m->subject).'</h4><p>'.esc_html(wp_trim_words($plain,35)).'</p><button type="button" class="button bcs-mail-preview" data-title="'.esc_attr($m->subject).'"><span class="dashicons dashicons-visibility"></span><span>Otwórz wiadomość</span></button><template class="bcs-mail-preview-template"><div class="bcs-mail-preview-meta"><strong>'.esc_html($m->direction==='inbound'?'Odebrano od rodzica':'Wysłano do rodzica').'</strong><span>'.esc_html(BCS_Utils::format_datetime($m->received_at)).'</span></div><div class="bcs-mail-preview-body">'.$body.'</div></template></article>';
        }
        return $html.'</div><p><a class="button" href="'.esc_url(admin_url('admin.php?page=bcs-mailbox')).'">Przejdź do modułu Poczta</a></p></div></details></section><div id="bcs-mail-preview-modal" class="bcs-contact-modal" hidden><div class="bcs-contact-modal__dialog bcs-mail-preview-dialog" role="dialog" aria-modal="true"><button type="button" class="bcs-mail-preview-close bcs-contact-modal__close" aria-label="Zamknij">×</button><div class="bcs-panel-head"><div><h2 data-bcs-mail-preview-title>Wiadomość e-mail</h2><p>Wizualizacja zapisanej wiadomości.</p></div><span class="dashicons dashicons-email-alt"></span></div><div data-bcs-mail-preview-content></div></div></div>';
    }

    private static function milestone_badges(object $r): string {
        $form=(
            !empty($r->form_verified_at)
            || !empty($r->has_form_verified_log)
            || !empty($r->has_form_verified_activity)
        );
        $agreement=(
            ($r->agreement_status??'')==='accepted'
            || !empty($r->has_signed_agreement)
        );
        $payment=(
            ((float)$r->total_amount>0 && (float)$r->paid_amount>=(float)$r->total_amount)
            || !empty($r->has_paid_payment)
        );
        $invoice=(
            ($r->invoice_status??'')==='sent'
            || !empty($r->invoice_sent_at)
            || !empty($r->has_sent_invoice)
        );
        $items=[['F','Formularz obozowy',$form,'milestone-form'],['U','Umowa',$agreement,'milestone-agreement'],['P','Płatność',$payment,'milestone-payment'],['FV','Faktura',$invoice,'milestone-invoice']];
        $html='<div class="bcs-milestones" aria-label="Etapy dokumentów i rozliczenia">';
        foreach($items as [$label,$title,$done,$class])$html.='<span class="bcs-milestone '.esc_attr($class).' '.($done?'is-done':'is-pending').'" title="'.esc_attr($title.($done?' – wykonano':' – oczekuje')).'">'.esc_html($label).'</span>';
        return $html.'</div>';
    }

    private static function status_class(string $status): string { return 'bcs-stage-'.sanitize_html_class($status); }

    private static function action_form(int $id,string $action,string $label,string $placeholder):void{echo '<form method="post" class="bcs-crm-action">';wp_nonce_field('bcs_crm_'.$id);echo '<input type="hidden" name="registration_id" value="'.$id.'"><input type="hidden" name="bcs_crm_action" value="'.$action.'"><textarea name="note" rows="2" placeholder="'.esc_attr($placeholder).'"></textarea><button class="button">'.esc_html($label).'</button></form>';}
    private static function list_action_form(int $id,string $action,string $label,string $class):string{ob_start();$payment_class=$action==='mark_paid'?' bcs-payment-date-action-02024':'';echo '<form method="post" class="bcs-list-action'.$payment_class.'">';wp_nonce_field('bcs_crm_'.$id);echo '<input type="hidden" name="registration_id" value="'.$id.'">'.($action==='mark_paid'?'<input type="hidden" name="payment_nonce" value="'.esc_attr(wp_create_nonce('bcs_payment_date_02024_'.$id)).'">':'').'<button class="'.esc_attr($class).'" name="bcs_crm_action" value="'.esc_attr($action).'">'.esc_html($label).'</button></form>';return (string)ob_get_clean();}
    private static function simple_action(int $id,string $action,string $label,string $class):void{echo '<form method="post" class="bcs-crm-action">';wp_nonce_field('bcs_crm_'.$id);echo '<input type="hidden" name="registration_id" value="'.$id.'"><button class="'.esc_attr($class).'" name="bcs_crm_action" value="'.$action.'">'.esc_html($label).'</button></form>';}
    private static function workflow_button(int $id,string $action,string $label,string $extra_class=''):string{$url=wp_nonce_url(add_query_arg(['action'=>'bcs_workflow_single','registration_id'=>$id,'workflow'=>$action],admin_url('admin-post.php')),'bcs_workflow_single_'.$id.'_'.$action);$is_payment=$action==='mark_bank_paid';$classes=trim('button bcs-action-available '.$extra_class.($is_payment?' bcs-payment-date-action-02024':''));return '<a class="'.esc_attr($classes).'" href="'.esc_url($url).'"'.($is_payment?' data-registration-id="'.$id.'" data-payment-nonce="'.esc_attr(wp_create_nonce('bcs_payment_date_02024_'.$id)).'"':'').'>'.esc_html($label).'</a>';}
    private static function log_labels(): array {return [
        'registration_created'=>'Utworzono zgłoszenie','registration_admin_confirmed'=>'Potwierdzono rejestrację','registration_edited_by_admin'=>'Edytowano dane zgłoszenia','registration_price_changed'=>'Zmieniono indywidualną cenę zgłoszenia','registration_cancelled'=>'Anulowano zgłoszenie',
        'parent_portal_invite_sent'=>'Wysłano link do formularza zgłoszeniowego','parent_form_completed'=>'Uzupełniono pełny formularz uczestnika','parent_form_updated'=>'Zaktualizowano pełny formularz uczestnika','parent_form_save_blocked'=>'Zablokowano zapis formularza podczas pracy administratora','camp_form_verified'=>'Zweryfikowano i zaakceptowano formularz obozowy',
        'agreement_template_opened'=>'Rodzic otworzył wzór umowy','agreement_opened_for_signature'=>'Rodzic po raz pierwszy otworzył umowę do podpisu','agreement_sent_by_admin'=>'Wysłano umowę do podpisu','agreement_signature_reminder_sent'=>'Wysłano przypomnienie o podpisaniu umowy','auto_agreement_reminder'=>'Automatycznie wysłano przypomnienie o podpisaniu umowy','auto_payment'=>'Automatycznie wysłano przypomnienie o płatności','auto_pre_camp'=>'Automatycznie wysłano informacje przed obozem','auto_reservation'=>'Automatycznie wysłano starsze przypomnienie o umowie','agreement_accepted'=>'Zaakceptowano umowę kodem SMS','agreement_withdrawn_before_signature'=>'Wycofano umowę przed podpisaniem','agreement_draft_edited'=>'Zaktualizowano draft umowy',
        'stripe_link_sent'=>'Wysłano link do płatności Stripe','stripe_link_email_failed'=>'Nie udało się wysłać linku do płatności Stripe','stripe_payment_confirmed'=>'Potwierdzono płatność Stripe','bank_payment_marked_paid'=>'Potwierdzono wpłatę przelewem','payment_confirmation_sent'=>'Wysłano potwierdzenie wpłaty','payment_reminder_sent'=>'Wysłano przypomnienie o płatności',
        'invoice_generated_manually'=>'Wygenerowano fakturę','invoice_duplicate_generation_blocked'=>'Zablokowano ponowne wygenerowanie faktury','invoice_downloaded_by_parent'=>'Rodzic pobrał fakturę','document_downloaded'=>'Pobrano dokument','document_download_denied'=>'Odrzucono próbę pobrania dokumentu',
        'crm_phone'=>'Wykonano telefon','crm_note'=>'Dodano notatkę','crm_task'=>'Dodano zadanie','crm_email'=>'Wysłano wiadomość e-mail','crm_invoice_sent'=>'Wysłano fakturę'
    ];}
    private static function log_label(string $event):string{
        $known=self::log_labels();
        if(isset($known[$event]))return $known[$event];
        $label=trim(str_replace('_',' ',$event));
        return $label!==''?ucfirst($label):'Zdarzenie systemowe';
    }
    private static function log_note(array $data):string{
        foreach(['note','message','error','reason','title','document','subject'] as $key){
            if(!isset($data[$key])||is_array($data[$key])||is_object($data[$key]))continue;
            $value=trim((string)$data[$key]);
            if($value!=='')return $value;
        }
        return '';
    }
    private static function status_legend(): string {
        $html='<div class="bcs-legend-list">';
        foreach(BCS_Workflow_Engine::statuses() as $key=>$label) {
            $html.='<div class="bcs-legend-row"><span class="bcs-badge '.esc_attr(self::status_class($key)).'">'.esc_html($label).'</span></div>';
        }
        return $html.'</div>';
    }
}
