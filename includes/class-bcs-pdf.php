<?php
if (!defined('ABSPATH')) exit;

class BCS_PDF {
    public static function init(): void {}

    public static function available(): bool {
        if (class_exists('Dompdf\Dompdf')) return true;
        $paths = [
            BCS_DIR.'vendor/autoload.php',
            WP_CONTENT_DIR.'/vendor/autoload.php',
            ABSPATH.'vendor/autoload.php',
        ];
        foreach ($paths as $path) {
            if (!file_exists($path)) continue;
            require_once $path;
            if (class_exists('Dompdf\Dompdf')) return true;
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

    private static function agreement_registration_context(): int {
        $request_id = absint($_GET['registration'] ?? $_POST['registration_id'] ?? 0);
        if ($request_id) return $request_id;

        foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 8) as $frame) {
            if (($frame['class'] ?? '') !== 'BCS_Documents') continue;
            if (($frame['function'] ?? '') !== 'agreement_pdf') continue;
            return absint($frame['args'][0] ?? 0);
        }
        return 0;
    }

    public static function generate(string $html, string $path, string $title='Dokument'): bool {
        if (!self::available()) return false;
        try {
            if (class_exists('BCS_Release_052')) {
                $registration_id = self::agreement_registration_context();
                $had_request_id = array_key_exists('registration', $_GET);
                $previous_request_id = $_GET['registration'] ?? null;
                if ($registration_id) $_GET['registration'] = $registration_id;
                $html = BCS_Release_052::prepare_pdf_html($html, $title);
                if ($registration_id) {
                    if ($had_request_id) $_GET['registration'] = $previous_request_id;
                    else unset($_GET['registration']);
                }
            }
            if (class_exists('BCS_Release_055')) {
                $html = BCS_Release_055::force_attachment_page($html);
            }
            if (class_exists('BCS_Release_057')) {
                $html = BCS_Release_057::prepare_agreement_html($html);
            }
            if (class_exists('BCS_Release_066')) {
                $html = BCS_Release_066::prepare_agreement_html($html);
            }
            if (class_exists('BCS_Release_067')) {
                $html = BCS_Release_067::prepare_agreement_html($html);
            }
            if (class_exists('BCS_Release_068')) {
                // Finalna warstwa usuwa kolidujące reguły @page, rezerwuje bezpieczne
                // marginesy oraz grupuje Załącznik nr 1 i dowody SMS na osobnych stronach.
                $html = BCS_Release_068::prepare_agreement_html($html);
            }
            $html = self::embed_local_assets($html);

            $options = new Dompdf\Options();
            $options->set('isRemoteEnabled', false);
            $options->set('defaultFont', 'DejaVu Sans');
            $options->set('chroot', WP_CONTENT_DIR);

            $pdf = new Dompdf\Dompdf($options);
            $pdf->setPaper('A4', 'portrait');
            $pdf->loadHtml($html, 'UTF-8');
            $pdf->render();

            if (class_exists('BCS_Release_068')) {
                BCS_Release_068::apply_canvas_header_footer($pdf, $html, $title);
            } elseif (class_exists('BCS_Release_067')) {
                BCS_Release_067::apply_canvas_header_footer($pdf, $html, $title);
            }

            return file_put_contents($path, $pdf->output()) !== false;
        } catch (Throwable $e) {
            BCS_Utils::log('pdf_error', ['message'=>$e->getMessage(), 'title'=>$title]);
            return false;
        }
    }
}
