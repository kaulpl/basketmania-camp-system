<?php
if (!defined('ABSPATH')) exit;

final class BCS_Release_11216 {
    private const OPTION='bcs_release_11216_parent_data_migrated';

    public static function init(): void {
        if (get_option(self::OPTION)) return;
        $saved=get_option('bcs_content_templates',[]);
        if (!is_array($saved)) $saved=[];
        $template=(string)($saved['documents']['agreement']??'');
        if ($template==='') {
            $path=BCS_DIR.'templates/agreement-default.html';
            if (is_readable($path)) $template=(string)file_get_contents($path);
        }
        $updated=self::transform_agreement_template($template);
        if ($updated!=='' && $updated!==$template) {
            if (!isset($saved['documents'])||!is_array($saved['documents'])) $saved['documents']=[];
            $saved['documents']['agreement']=$updated;
            update_option('bcs_content_templates',$saved,false);
        }
        update_option(self::OPTION,1,false);
    }

    public static function transform_agreement_template(string $html): string {
        if ($html==='' || str_contains($html,'{{SECOND_PARENT_PHONE}}')) return $html;
        $table='<table class="bcs-parent-data"><tr><td><strong>Rodzic / opiekun prawny 1:</strong></td><td>{{PARENT_NAME}}</td></tr><tr><td><strong>Adres zamieszkania:</strong></td><td>{{PARENT_ADDRESS}}</td></tr><tr><td><strong>Telefon:</strong></td><td>{{PARENT_PHONE}}</td></tr><tr><td><strong>E-mail:</strong></td><td>{{PARENT_EMAIL}}</td></tr><tr><td><strong>Rodzic / opiekun prawny 2:</strong></td><td>{{SECOND_PARENT_NAME}}</td></tr><tr><td><strong>Telefon:</strong></td><td>{{SECOND_PARENT_PHONE}}</td></tr><tr><td><strong>E-mail:</strong></td><td>{{SECOND_PARENT_EMAIL}}</td></tr><tr><td><strong>Samodzielne sprawowanie opieki:</strong></td><td>{{SOLE_GUARDIAN}}</td></tr></table>';
        return (string)preg_replace(
            '~<table\b[^>]*>(?:(?!</table>).)*\{\{PARENT_NAME\}\}.*?</table>~isu',
            $table,
            $html,
            1
        );
    }
}
