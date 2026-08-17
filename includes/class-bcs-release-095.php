<?php
if (!defined('ABSPATH')) exit;

/**
 * 0.95 – poprawiona czytelność drabinki A3, zawijanie nazw i pełne UTF-8.
 */
final class BCS_Release_095 {
    private const ACTION = 'bcs_camp_bracket_pdf_094';

    public static function init(): void {
        remove_action('admin_post_'.self::ACTION, [BCS_Release_094::class, 'bracket_pdf']);
        add_action('admin_post_'.self::ACTION, [__CLASS__, 'bracket_pdf'], 20);
    }

    private static function unicode_codepoint(string $char): int {
        $bytes = array_values(unpack('C*', $char) ?: []);
        if (!$bytes) return 0;
        $b0 = $bytes[0];
        if ($b0 < 0x80) return $b0;
        if (($b0 & 0xE0) === 0xC0 && isset($bytes[1])) {
            return (($b0 & 0x1F) << 6) | ($bytes[1] & 0x3F);
        }
        if (($b0 & 0xF0) === 0xE0 && isset($bytes[1], $bytes[2])) {
            return (($b0 & 0x0F) << 12) | (($bytes[1] & 0x3F) << 6) | ($bytes[2] & 0x3F);
        }
        if (($b0 & 0xF8) === 0xF0 && isset($bytes[1], $bytes[2], $bytes[3])) {
            return (($b0 & 0x07) << 18) | (($bytes[1] & 0x3F) << 12) | (($bytes[2] & 0x3F) << 6) | ($bytes[3] & 0x3F);
        }
        return 0;
    }

    public static function xml_text(string $value): string {
        $escaped = htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
        return preg_replace_callback('/[^\x00-\x7F]/u', static function(array $match): string {
            $codepoint = self::unicode_codepoint($match[0]);
            return $codepoint > 0 ? '&#x'.strtoupper(dechex($codepoint)).';' : '';
        }, $escaped) ?? $escaped;
    }

    private static function ulen(string $value): int {
        $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
        return is_array($chars) ? count($chars) : strlen($value);
    }

    /** @return string[]|null */
    private static function wrap_words(string $label, int $maxChars): ?array {
        $words = preg_split('/\s+/u', trim($label), -1, PREG_SPLIT_NO_EMPTY);
        if (!$words) return [''];
        $lines = [];
        $current = '';
        foreach ($words as $word) {
            if (self::ulen($word) > $maxChars) return null;
            $candidate = $current === '' ? $word : $current.' '.$word;
            if (self::ulen($candidate) <= $maxChars) {
                $current = $candidate;
                continue;
            }
            if ($current !== '') $lines[] = $current;
            $current = $word;
            if (count($lines) >= 2) return null;
        }
        if ($current !== '') $lines[] = $current;
        return count($lines) <= 2 ? $lines : null;
    }

    public static function participant_label(?object $participant): string {
        if (!$participant) return 'WOLNY LOS';
        $number = (int)($participant->jersey_number ?? 0);
        $name = trim((string)($participant->child_first_name ?? '').' '.(string)($participant->child_last_name ?? ''));
        return '[#'.($number > 0 ? $number : '-').'] '.$name;
    }

    /**
     * @return array{lines:array<int,string>,font_size:float}
     */
    public static function fit_participant_label(?object $participant, float $boxWidth, float $preferredFont, float $minFont = 2.8): array {
        $label = self::participant_label($participant);
        if (!$participant) return ['lines'=>[$label], 'font_size'=>$preferredFont];

        for ($font = $preferredFont; $font >= $minFont; $font -= 0.2) {
            $maxChars = max(7, (int)floor(max(10.0, $boxWidth - 8.0) / max(1.0, $font * 0.56)));
            if (self::ulen($label) <= $maxChars) {
                return ['lines'=>[$label], 'font_size'=>round($font, 2)];
            }
            $lines = self::wrap_words($label, $maxChars);
            if ($lines !== null) {
                return ['lines'=>$lines, 'font_size'=>round($font, 2)];
            }
        }

        $words = preg_split('/\s+/u', trim($label), -1, PREG_SPLIT_NO_EMPTY) ?: [$label];
        $mid = max(1, (int)ceil(count($words) / 2));
        return [
            'lines'=>[
                implode(' ', array_slice($words, 0, $mid)),
                implode(' ', array_slice($words, $mid)),
            ],
            'font_size'=>$minFont,
        ];
    }

    /** @return array<string,float|int> */
    public static function layout_metrics(int $participantCount): array {
        $bracketSize = BCS_Release_094::next_power_of_two(max(2, $participantCount));
        $sideSlots = max(1, intdiv($bracketSize, 2));
        $stageCount = 1;
        for ($slots = $sideSlots; $slots > 1; $slots = intdiv($slots, 2)) $stageCount++;

        $top = 111.0;
        $height = 650.0;
        $outer = 22.0;
        $innerGap = 165.0;
        $inner = 560.0 - $innerGap;
        $stageStep = $stageCount > 1 ? (($inner - $outer) / ($stageCount - 1)) : 0.0;
        $boxWidth = $stageCount > 1 ? max(50.0, min(122.0, $stageStep - 5.0)) : 122.0;
        $verticalStep = $height / $sideSlots;
        $maxFirstBoxHeight = max(8.0, min(27.0, $verticalStep * 0.88));
        $preferredFont = $sideSlots <= 8 ? 9.2 : ($sideSlots <= 16 ? 7.4 : ($sideSlots <= 32 ? 6.1 : 3.6));

        return [
            'bracket_size'=>$bracketSize,
            'side_slots'=>$sideSlots,
            'stage_count'=>$stageCount,
            'top'=>$top,
            'height'=>$height,
            'outer'=>$outer,
            'inner_gap'=>$innerGap,
            'inner'=>$inner,
            'stage_step'=>$stageStep,
            'box_width'=>$boxWidth,
            'vertical_step'=>$verticalStep,
            'max_first_box_height'=>$maxFirstBoxHeight,
            'preferred_font'=>$preferredFont,
        ];
    }

    /** @return float[] */
    private static function stage_positions(int $slotCount, float $top, float $height): array {
        if ($slotCount <= 0) return [];
        $step = $height / $slotCount;
        $positions = [];
        for ($i = 0; $i < $slotCount; $i++) $positions[] = $top + ($i + 0.5) * $step;
        return $positions;
    }

    /** @return array<int,array<int,float>> */
    private static function all_stage_positions(int $sideSlots, float $top, float $height): array {
        $stages = [self::stage_positions($sideSlots, $top, $height)];
        while (count($stages[count($stages)-1]) > 1) {
            $previous = $stages[count($stages)-1];
            $next = [];
            for ($i = 0; $i < count($previous); $i += 2) {
                $next[] = ($previous[$i] + $previous[$i+1]) / 2;
            }
            $stages[] = $next;
        }
        return $stages;
    }

    /** @return float[] */
    private static function stage_x_positions(int $stageCount, bool $left, array $metrics): array {
        if ($stageCount <= 1) return [$left ? (float)$metrics['inner'] : (1120.0 - (float)$metrics['inner'])];
        $xs = [];
        for ($i = 0; $i < $stageCount; $i++) {
            $x = (float)$metrics['outer'] + $i * (float)$metrics['stage_step'];
            $xs[] = $left ? $x : 1120.0 - $x;
        }
        return $xs;
    }

    private static function connector_svg(array $positions, array $xs, bool $left, float $boxWidth): string {
        $svg = '';
        for ($stage = 0; $stage < count($positions)-1; $stage++) {
            $current = $positions[$stage];
            $next = $positions[$stage+1];
            for ($i = 0; $i < count($next); $i++) {
                $a = $current[$i*2];
                $b = $current[$i*2+1];
                $mid = $next[$i];
                if ($left) {
                    $fromX = $xs[$stage] + $boxWidth;
                    $toX = $xs[$stage+1];
                } else {
                    $fromX = $xs[$stage] - $boxWidth;
                    $toX = $xs[$stage+1];
                }
                $jointX = ($fromX + $toX) / 2;
                $svg .= '<path d="M'.$fromX.' '.$a.' H'.$jointX.' V'.$b.' M'.$fromX.' '.$b.' H'.$jointX.' M'.$jointX.' '.$mid.' H'.$toX.'" class="line"/>';
            }
        }
        return $svg;
    }

    private static function boxes_svg(array $positions, array $xs, bool $left, array $slots, array $metrics): string {
        $svg = '';
        $boxWidth = (float)$metrics['box_width'];
        $preferredFont = (float)$metrics['preferred_font'];
        $maxFirstBoxHeight = (float)$metrics['max_first_box_height'];
        $laterBoxHeight = max(8.0, min(16.0, $maxFirstBoxHeight));

        foreach ($positions as $stage => $ys) {
            foreach ($ys as $index => $y) {
                $isFirst = $stage === 0;
                $x = $left ? $xs[$stage] : $xs[$stage] - $boxWidth;
                $class = 'box';
                $participant = null;
                $fit = ['lines'=>[''], 'font_size'=>$preferredFont];
                $boxHeight = $laterBoxHeight;

                if ($isFirst) {
                    $slotIndex = $left ? $index : (count($slots) - 1 - $index);
                    $participant = $slots[$slotIndex] ?? null;
                    $fit = self::fit_participant_label($participant, $boxWidth, $preferredFont);
                    if (!$participant) $class .= ' bye';
                    $lineCount = max(1, count($fit['lines']));
                    $lineHeight = (float)$fit['font_size'] * 1.08;
                    $neededHeight = $lineCount === 1 ? ($lineHeight + 4.0) : ($lineHeight * $lineCount + 3.0);
                    $boxHeight = min($maxFirstBoxHeight, max(8.0, $neededHeight));
                }

                $svg .= '<rect x="'.$x.'" y="'.($y-$boxHeight/2).'" width="'.$boxWidth.'" height="'.$boxHeight.'" rx="2" class="'.$class.'"/>';
                if ($isFirst) {
                    $textX = $left ? ($x+4) : ($x+$boxWidth-4);
                    $anchor = $left ? 'start' : 'end';
                    $fontSize = (float)$fit['font_size'];
                    $lineHeight = $fontSize * 1.08;
                    $lines = $fit['lines'];
                    $startY = $y - ((count($lines)-1) * $lineHeight / 2) + ($fontSize * 0.34);
                    foreach ($lines as $lineIndex => $line) {
                        $textY = $startY + $lineIndex * $lineHeight;
                        $svg .= '<text x="'.$textX.'" y="'.$textY.'" text-anchor="'.$anchor.'" class="entrant" style="font-size:'.$fontSize.'px">'.self::xml_text($line).'</text>';
                    }
                } else {
                    $svg .= '<line x1="'.($x+6).'" y1="'.($y+1).'" x2="'.($x+$boxWidth-6).'" y2="'.($y+1).'" class="write"/>';
                }
            }
        }
        return $svg;
    }

    public static function build_bracket_svg(array $participants, object $camp): string {
        $participants = BCS_Release_094::randomized($participants);
        $slots = BCS_Release_094::first_round_slots($participants);
        $metrics = self::layout_metrics(count($participants));
        $sideSlots = (int)$metrics['side_slots'];
        $positions = self::all_stage_positions($sideSlots, (float)$metrics['top'], (float)$metrics['height']);
        $stageCount = count($positions);
        $leftXs = self::stage_x_positions($stageCount, true, $metrics);
        $rightXs = self::stage_x_positions($stageCount, false, $metrics);
        $boxWidth = (float)$metrics['box_width'];

        $campName = self::xml_text((string)($camp->name ?? 'Turnus Basketmania Camp'));
        $dates = trim((string)($camp->start_date ?? '').' - '.(string)($camp->end_date ?? ''), ' -');
        $location = trim((string)($camp->location ?? ''));
        $meta = self::xml_text(trim($dates.($dates !== '' && $location !== '' ? ' - ' : '').$location));

        $svg = '<?xml version="1.0" encoding="UTF-8"?>';
        $svg .= '<svg xmlns="http://www.w3.org/2000/svg" width="1120" height="790" viewBox="0 0 1120 790">';
        $svg .= '<style>'
            .'.title{font-family:"DejaVu Sans";font-size:21px;font-weight:700;fill:#162033}'
            .'.meta{font-family:"DejaVu Sans";font-size:10px;font-weight:400;fill:#5b6474}'
            .'.label{font-family:"DejaVu Sans";font-size:11px;font-weight:700;fill:#162033}'
            .'.round{font-family:"DejaVu Sans";font-size:8px;font-weight:700;fill:#526070}'
            .'.entrant{font-family:"DejaVu Sans";font-weight:400;fill:#111827}'
            .'.box{fill:#fff;stroke:#64748b;stroke-width:1}'
            .'.box.bye{fill:#f4f6f8;stroke:#a8b0bb}'
            .'.line{fill:none;stroke:#475569;stroke-width:1}'
            .'.write{stroke:#9aa4b2;stroke-width:.8;stroke-dasharray:3 2}'
            .'.final{fill:#f7fbff;stroke:#2271b1;stroke-width:1.6}'
            .'.finalTitle{font-family:"DejaVu Sans";font-size:14px;font-weight:700;fill:#135e96}'
            .'.small{font-family:"DejaVu Sans";font-size:8px;font-weight:400;fill:#6b7280}'
        .'</style>';
        $svg .= '<rect x="0" y="0" width="1120" height="790" fill="#fff"/>';
        $svg .= '<text x="560" y="27" text-anchor="middle" class="title">DRABINKA PUCHAROWA</text>';
        $svg .= '<text x="560" y="49" text-anchor="middle" class="label">Nazwa konkursu / turnieju: .......................................................................................................</text>';
        $svg .= '<text x="560" y="69" text-anchor="middle" class="meta">'.$campName.'</text>';
        if ($meta !== '') $svg .= '<text x="560" y="84" text-anchor="middle" class="meta">'.$meta.'</text>';

        foreach ($leftXs as $stage => $x) {
            $round = $stage === 0 ? 'START' : 'RUNDA '.($stage+1);
            $svg .= '<text x="'.($x+$boxWidth/2).'" y="104" text-anchor="middle" class="round">'.$round.'</text>';
        }
        foreach ($rightXs as $stage => $x) {
            $round = $stage === 0 ? 'START' : 'RUNDA '.($stage+1);
            $svg .= '<text x="'.($x-$boxWidth/2).'" y="104" text-anchor="middle" class="round">'.$round.'</text>';
        }

        $svg .= self::connector_svg($positions, $leftXs, true, $boxWidth);
        $svg .= self::connector_svg($positions, $rightXs, false, $boxWidth);
        $svg .= self::boxes_svg($positions, $leftXs, true, $slots, $metrics);
        $svg .= self::boxes_svg($positions, $rightXs, false, $slots, $metrics);

        $finalY = 435.0;
        $finalW = 150.0;
        $finalH = 102.0;
        $finalX = 560.0 - $finalW/2;
        $leftLastX = $leftXs[count($leftXs)-1] + $boxWidth;
        $rightLastX = $rightXs[count($rightXs)-1] - $boxWidth;
        $lastY = $positions[count($positions)-1][0];
        $svg .= '<line x1="'.$leftLastX.'" y1="'.$lastY.'" x2="'.$finalX.'" y2="'.$finalY.'" class="line"/>';
        $svg .= '<line x1="'.$rightLastX.'" y1="'.$lastY.'" x2="'.($finalX+$finalW).'" y2="'.$finalY.'" class="line"/>';
        $svg .= '<rect x="'.$finalX.'" y="'.($finalY-$finalH/2).'" width="'.$finalW.'" height="'.$finalH.'" rx="6" class="final"/>';
        $svg .= '<text x="560" y="'.($finalY-27).'" text-anchor="middle" class="finalTitle">'.self::xml_text('FINAŁ').'</text>';
        $svg .= '<line x1="'.($finalX+14).'" y1="'.($finalY-8).'" x2="'.($finalX+$finalW-14).'" y2="'.($finalY-8).'" class="write"/>';
        $svg .= '<line x1="'.($finalX+14).'" y1="'.($finalY+15).'" x2="'.($finalX+$finalW-14).'" y2="'.($finalY+15).'" class="write"/>';
        $svg .= '<text x="560" y="'.($finalY+38).'" text-anchor="middle" class="small">'.self::xml_text('ZWYCIĘZCA').'</text>';
        $svg .= '<line x1="'.($finalX+28).'" y1="'.($finalY+45).'" x2="'.($finalX+$finalW-28).'" y2="'.($finalY+45).'" class="write"/>';
        $svg .= '</svg>';
        return $svg;
    }

    private static function camp(int $campId): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM ".BCS_DB::table('camps')." WHERE id=%d", $campId));
    }

    public static function bracket_pdf(): void {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień.');
        $campId = absint($_GET['camp_id'] ?? 0);
        check_admin_referer(self::ACTION.'_'.$campId);
        $camp = self::camp($campId);
        if (!$camp) wp_die('Nie znaleziono turnusu.');

        $refresh = BCS_Release_092::refresh_jersey_numbers($campId);
        if (empty($refresh['success'])) wp_die(esc_html((string)($refresh['message'] ?? 'Nie udało się odświeżyć numerów koszulek.')));

        $participants = BCS_Release_094::paid_participants($campId);
        if (count($participants) < 2) wp_die('Do wygenerowania drabinki potrzeba co najmniej 2 zarejestrowanych i opłaconych uczestników.');
        if (count($participants) > 128) wp_die('Drabinka A3 obsługuje maksymalnie 128 uczestników.');

        if (!BCS_PDF::available()) wp_die('Silnik PDF nie jest dostępny.');
        try {
            $svg = self::build_bracket_svg($participants, $camp);
            $dataUri = 'data:image/svg+xml;base64,'.base64_encode($svg);
            $html = '<!doctype html><html lang="pl"><head><meta charset="UTF-8"><style>@page{size:A3 landscape;margin:6mm}html,body{margin:0;padding:0;width:100%;height:100%}img{display:block;width:100%;height:auto}</style></head><body><img src="'.$dataUri.'" alt="Drabinka pucharowa"></body></html>';

            $options = new Dompdf\Options();
            $options->set('isRemoteEnabled', false);
            $options->set('defaultFont', 'DejaVu Sans');
            $options->set('defaultMediaType', 'print');
            $pdf = new Dompdf\Dompdf($options);
            $pdf->setPaper('A3', 'landscape');
            $pdf->loadHtml($html, 'UTF-8');
            $pdf->render();
            $bytes = $pdf->output();

            BCS_Utils::log('camp_bracket_generated_095', [
                'camp_id'=>$campId,
                'participants'=>count($participants),
                'bracket_size'=>BCS_Release_094::next_power_of_two(count($participants)),
            ]);

            nocache_headers();
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="drabinka-turnus-'.$campId.'.pdf"');
            header('Content-Length: '.strlen($bytes));
            echo $bytes;
            exit;
        } catch (Throwable $e) {
            BCS_Utils::log('pdf_error', ['message'=>$e->getMessage(), 'title'=>'Drabinka pucharowa', 'camp_id'=>$campId]);
            wp_die('Nie udało się wygenerować drabinki PDF.');
        }
    }
}
