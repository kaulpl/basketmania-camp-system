<?php
if (!defined('ABSPATH')) exit;

class BCS_PDF {
    public static function init(): void {}
    public static function available(): bool {
        if (class_exists('Dompdf\\Dompdf')) return true;
        $paths=[BCS_DIR.'vendor/autoload.php',WP_CONTENT_DIR.'/vendor/autoload.php',ABSPATH.'vendor/autoload.php'];
        foreach($paths as $p) if(file_exists($p)){require_once $p;if(class_exists('Dompdf\\Dompdf'))return true;}
        return false;
    }
    public static function generate(string $html,string $path,string $title='Dokument'): bool {
        if(!self::available()) return false;
        try{
            $options=new Dompdf\Options();$options->set('isRemoteEnabled',false);$options->set('defaultFont','DejaVu Sans');$options->set('chroot',WP_CONTENT_DIR);
            $pdf=new Dompdf\Dompdf($options);$pdf->setPaper('A4','portrait');$pdf->loadHtml($html,'UTF-8');$pdf->render();
            return file_put_contents($path,$pdf->output())!==false;
        }catch(Throwable $e){BCS_Utils::log('pdf_error',['message'=>$e->getMessage()]);return false;}
    }
}
