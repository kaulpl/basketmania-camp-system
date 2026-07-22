<?php
if (!defined('ABSPATH')) exit;

/** Centralny menedżer krótkich blokad edycji danych rodzica. */
class BCS_Locks {
    public static function init(): void {}
    public static function ttl(): int {
        $settings=get_option('bcs_settings',[]);
        $minutes=max(1,min(30,absint($settings['registration_lock_minutes']??3)));
        return $minutes*MINUTE_IN_SECONDS;
    }
    private static function key(int $registration_id): string { return 'bcs_registration_review_'.$registration_id; }
    public static function touch(int $registration_id, int $admin_id=0): array {
        $now=time(); $old=get_transient(self::key($registration_id)); $ttl=self::ttl();
        $payload=['registration_id'=>$registration_id,'admin_id'=>$admin_id?:get_current_user_id(),'touched_at'=>$now,'expires_at'=>$now+$ttl];
        set_transient(self::key($registration_id),$payload,$ttl);
        if(!$old && class_exists('BCS_Utils')) BCS_Utils::log('registration_edit_lock_started',['expires_at'=>wp_date('Y-m-d H:i:s',$payload['expires_at']),'duration_seconds'=>$ttl],$registration_id,null);
        return $payload;
    }
    public static function get(int $registration_id): array {
        $value=get_transient(self::key($registration_id));
        if(!$value) return [];
        if(!is_array($value)) return ['registration_id'=>$registration_id,'admin_id'=>0,'touched_at'=>time(),'expires_at'=>time()+self::ttl()];
        if((int)($value['expires_at']??0)<=time()){ delete_transient(self::key($registration_id)); return []; }
        return $value;
    }
    public static function active(int $registration_id): bool { return (bool)self::get($registration_id); }
    public static function remaining(int $registration_id): int { $v=self::get($registration_id); return $v?max(0,(int)$v['expires_at']-time()):0; }
}
