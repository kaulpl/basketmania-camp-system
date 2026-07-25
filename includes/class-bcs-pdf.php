<?php
if (!defined('ABSPATH')) exit;

class BCS_PDF {
    public static function init(): void {}

    public static function available(): bool {
        if (class_exists('Dompdf\\Dompdf')) return true;
        $paths = [
            BCS_DIR.'vendor/autoload.php',
            WP_CONTENT_DIR.'/vendor/autoload.php',
            ABSPATH.'vendor/autoload.php',
        ];
        foreach ($paths as $path) {
            if (!file_exists($path)) continue;
            require_once $path;
            if (class_exists('Dompdf\\Dompdf')) return true;
        }
        return false;
    }

    private static function embed_local_assets(string $html): string {
        $logo_path = BCS_DIR.'assets/images/logo-basketmania-camp-white.png';
        if (!is_readable($logo_path)) return $html;

        $bytes = file_get_contents($logo_path);
        if (!is_string($bytes) || $bytes === '') return $html;

        $data_uri = 'data:image/png;base64,'.base64_encode($bytes);
        $logo_url = BCS_URL.'assets/images/logo-basketmania-camp-white.png';
        return str_replace(
            [
                $logo_url,
                esc_url($logo_url),
                esc_attr($logo_url),
            ],
            $data_uri,
            $html
        );
    }

    public static function generate(string $html, string $path, string $title='Dokument'): bool {
        if (!self::available()) return false;
        try {
            $html = self::embed_local_assets($html);
            $options = new Dompdf\Options();
            $options->set('isRemoteEnabled', false);
            $options->set('defaultFont', 'DejaVu Sans');
            $options->set('chroot', WP_CONTENT_DIR);

            $pdf = new Dompdf\Dompdf($options);
            $pdf->setPaper('A4', 'portrait');
            $pdf->loadHtml($html, 'UTF-8');
            $pdf->render();
            return file_put_contents($path, $pdf->output()) !== false;
        } catch (Throwable $e) {
            BCS_Utils::log('pdf_error', ['message'=>$e->getMessage(), 'title'=>$title]);
            return false;
        }
    }
}
