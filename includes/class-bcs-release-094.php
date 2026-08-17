<?php
if (!defined('ABSPATH')) exit;

/**
 * 0.94 – losowa drabinka pucharowa A3 dla opłaconych uczestników turnusu.
 */
final class BCS_Release_094 {
    private const ACTION = 'bcs_camp_bracket_pdf_094';

    public static function init(): void {
        add_action('admin_post_'.self::ACTION, [__CLASS__, 'bracket_pdf']);
        add_action('admin_footer', [__CLASS__, 'render_bracket_buttons']);
    }

    private static function action_url(int $campId): string {
        return add_query_arg([
            'action'=>self::ACTION,
            'camp_id'=>$campId,
            '_wpnonce'=>wp_create_nonce(self::ACTION.'_'.$campId),
        ], admin_url('admin-post.php'));
    }

    public static function render_bracket_buttons(): void {
        if (!current_user_can('manage_options')) return;
        $page = sanitize_key(wp_unslash($_GET['page'] ?? ''));
        if (!in_array($page, ['bcs-camps','bcs-dashboard'], true)) return;

        global $wpdb;
        $camps = $wpdb->get_results("SELECT id FROM ".BCS_DB::table('camps')." ORDER BY start_date DESC");
        $links = [];
        foreach ((array)$camps as $camp) {
            $id = (int)$camp->id;
            $links[(string)$id] = self::action_url($id);
        }
        ?>
        <style>
            .bcs-bracket-btn-094.button-primary{background:#2271b1;border-color:#2271b1;color:#fff}
            .bcs-bracket-btn-094.button-primary:hover{background:#135e96;border-color:#135e96;color:#fff}
        </style>
        <script>
        (() => {
            const links = <?php echo wp_json_encode($links); ?>;
            const mount = () => {
                document.querySelectorAll('a[href*="page=bcs-camps&edit="]').forEach((editLink) => {
                    const match = editLink.href.match(/[?&]edit=(\d+)/);
                    if (!match || !links[match[1]]) return;
                    const container = editLink.closest('.bcs-card-actions') || editLink.parentElement;
                    if (!container || container.querySelector('[data-bcs-bracket-094="'+match[1]+'"]')) return;
                    const button = document.createElement('a');
                    button.className = 'button button-primary bcs-bracket-btn-094';
                    button.dataset.bcsBracket094 = match[1];
                    button.href = links[match[1]];
                    button.target = '_blank';
                    button.rel = 'noopener';
                    button.textContent = 'Generuj drabinkę';
                    container.appendChild(button);
                });
            };
            if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount, {once:true});
            else mount();
        })();
        </script>
        <?php
    }

    /** @return object[] */
    public static function paid_participants(int $campId): array {
        if ($campId <= 0) return [];
        global $wpdb;
        $registrations = BCS_DB::table('registrations');
        $payments = BCS_DB::table('payments');
        return (array)$wpdb->get_results($wpdb->prepare(
            "SELECT r.id,r.jersey_number,r.child_first_name,r.child_last_name,r.shirt_size,r.total_amount,r.paid_amount
             FROM {$registrations} r
             WHERE r.camp_id=%d
               AND r.status<>'cancelled'
               AND (
                    (r.total_amount>0 AND r.paid_amount>=r.total_amount)
                    OR EXISTS(SELECT 1 FROM {$payments} p WHERE p.registration_id=r.id AND p.status='paid')
               )
             ORDER BY r.id ASC",
            $campId
        ));
    }

    public static function next_power_of_two(int $count): int {
        if ($count <= 2) return 2;
        $power = 2;
        while ($power < $count && $power < 128) $power *= 2;
        return min(128, $power);
    }

    /** @return array<int,mixed> */
    public static function randomized(array $participants): array {
        $items = array_values($participants);
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }
        return $items;
    }

    /**
     * Buduje sloty pierwszej rundy tak, aby każdy wolny los był połączony z zawodnikiem,
     * dopóki liczba uczestników jest większa niż połowa rozmiaru drabinki.
     *
     * @return array<int,object|null>
     */
    public static function first_round_slots(array $participants): array {
        $participants = array_values($participants);
        $count = count($participants);
        if ($count < 2) return $participants;
        $size = self::next_power_of_two($count);
        $matches = intdiv($size, 2);
        $byes = $size - $count;
        $byeFlags = array_merge(array_fill(0, $byes, true), array_fill(0, $matches - $byes, false));
        // Wolne losy również rozkładamy losowo między meczami.
        for ($i = count($byeFlags) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$byeFlags[$i], $byeFlags[$j]] = [$byeFlags[$j], $byeFlags[$i]];
        }

        $slots = [];
        $cursor = 0;
        foreach ($byeFlags as $bye) {
            if ($bye) {
                $participant = $participants[$cursor++] ?? null;
                if (random_int(0, 1) === 0) {
                    $slots[] = $participant; $slots[] = null;
                } else {
                    $slots[] = null; $slots[] = $participant;
                }
                continue;
            }
            $slots[] = $participants[$cursor++] ?? null;
            $slots[] = $participants[$cursor++] ?? null;
        }
        return $slots;
    }

    public static function participant_label(?object $participant): string {
        if (!$participant) return 'WOLNY LOS';
        $number = (int)($participant->jersey_number ?? 0);
        $name = trim((string)($participant->child_first_name ?? '').' '.(string)($participant->child_last_name ?? ''));
        return '[#'.($number > 0 ? $number : '—').'] '.$name;
    }

    private static function xml(string $value): string {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /** @return float[] */
    private static function stage_positions(int $slotCount, float $top, float $height): array {
        $positions = [];
        if ($slotCount <= 0) return $positions;
        $step = $height / $slotCount;
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

    private static function stage_x_positions(int $stageCount, bool $left): array {
        $center = 560.0;
        $innerGap = 105.0;
        $outer = 24.0;
        $inner = $center - $innerGap;
        if ($stageCount <= 1) return [$left ? $inner : (1120.0 - $inner)];
        $step = ($inner - $outer) / ($stageCount - 1);
        $xs = [];
        for ($i = 0; $i < $stageCount; $i++) {
            $x = $outer + $i * $step;
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
                    $jointX = ($fromX + $toX) / 2;
                } else {
                    $fromX = $xs[$stage] - $boxWidth;
                    $toX = $xs[$stage+1];
                    $jointX = ($fromX + $toX) / 2;
                }
                $svg .= '<path d="M'.$fromX.' '.$a.' H'.$jointX.' V'.$b.' M'.$fromX.' '.$b.' H'.$jointX.' M'.$jointX.' '.$mid.' H'.$toX.'" class="line"/>';
            }
        }
        return $svg;
    }

    private static function boxes_svg(array $positions, array $xs, bool $left, array $slots, float $boxWidth, float $boxHeight, float $fontSize): string {
        $svg = '';
        foreach ($positions as $stage => $ys) {
            foreach ($ys as $index => $y) {
                $isFirst = $stage === 0;
                $x = $left ? $xs[$stage] : $xs[$stage] - $boxWidth;
                $label = '';
                $class = 'box';
                if ($isFirst) {
                    $slotIndex = $left ? $index : (count($slots) - 1 - $index);
                    $participant = $slots[$slotIndex] ?? null;
                    $label = self::participant_label($participant);
                    if (!$participant) $class .= ' bye';
                }
                $svg .= '<rect x="'.$x.'" y="'.($y-$boxHeight/2).'" width="'.$boxWidth.'" height="'.$boxHeight.'" rx="2" class="'.$class.'"/>';
                if ($isFirst) {
                    $textX = $left ? ($x+4) : ($x+$boxWidth-4);
                    $anchor = $left ? 'start' : 'end';
                    $svg .= '<text x="'.$textX.'" y="'.($y+$fontSize*0.34).'" text-anchor="'.$anchor.'" class="entrant" style="font-size:'.$fontSize.'px">'.self::xml($label).'</text>';
                } else {
                    $lineY = $y + 1;
                    $svg .= '<line x1="'.($x+6).'" y1="'.$lineY.'" x2="'.($x+$boxWidth-6).'" y2="'.$lineY.'" class="write"/>';
                }
            }
        }
        return $svg;
    }

    public static function build_bracket_svg(array $participants, object $camp): string {
        $participants = self::randomized($participants);
        $slots = self::first_round_slots($participants);
        $bracketSize = max(2, count($slots));
        $sideSlots = max(1, intdiv($bracketSize, 2));
        $top = 125.0;
        $height = 610.0;
        $positions = self::all_stage_positions($sideSlots, $top, $height);
        $stageCount = count($positions);
        $leftXs = self::stage_x_positions($stageCount, true);
        $rightXs = self::stage_x_positions($stageCount, false);
        $boxWidth = max(54.0, min(135.0, 395.0 / max(1, $stageCount)));
        $verticalStep = $height / max(1, $sideSlots);
        $boxHeight = max(7.0, min(22.0, $verticalStep * 0.72));
        $fontSize = max(5.2, min(10.5, $boxHeight * 0.56));

        $campName = self::xml((string)($camp->name ?? 'Turnus Basketmania Camp'));
        $dates = trim((string)($camp->start_date ?? '').' – '.(string)($camp->end_date ?? ''), ' –');
        $meta = self::xml(trim($dates.($dates !== '' ? ' · ' : '').(string)($camp->location ?? '')));
        $count = count($participants);

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="1120" height="790" viewBox="0 0 1120 790">';
        $svg .= '<style>.title{font:700 21px DejaVu Sans,sans-serif;fill:#162033}.meta{font:10px DejaVu Sans,sans-serif;fill:#5b6474}.label{font:700 11px DejaVu Sans,sans-serif;fill:#162033}.round{font:700 8px DejaVu Sans,sans-serif;fill:#526070}.entrant{font-family:DejaVu Sans,sans-serif;fill:#111827}.box{fill:#fff;stroke:#64748b;stroke-width:1}.box.bye{fill:#f4f6f8;stroke:#a8b0bb}.line{fill:none;stroke:#475569;stroke-width:1}.write{stroke:#9aa4b2;stroke-width:.8;stroke-dasharray:3 2}.final{fill:#f7fbff;stroke:#2271b1;stroke-width:1.6}.finalTitle{font:700 14px DejaVu Sans,sans-serif;fill:#135e96}.small{font:8px DejaVu Sans,sans-serif;fill:#6b7280}</style>';
        $svg .= '<rect x="0" y="0" width="1120" height="790" fill="#fff"/>';
        $svg .= '<text x="560" y="28" text-anchor="middle" class="title">DRABINKA PUCHAROWA</text>';
        $svg .= '<text x="560" y="50" text-anchor="middle" class="label">Nazwa konkursu / turnieju: .......................................................................................................</text>';
        $svg .= '<text x="560" y="69" text-anchor="middle" class="meta">'.$campName.'</text>';
        if ($meta !== '') $svg .= '<text x="560" y="84" text-anchor="middle" class="meta">'.$meta.'</text>';
        $svg .= '<text x="24" y="106" class="small">Losowanie automatyczne · uczestnicy zarejestrowani i opłaceni: '.$count.'</text>';
        $svg .= '<text x="1096" y="106" text-anchor="end" class="small">Format wydruku: A3 poziomo</text>';

        // Nagłówki rund po obu stronach.
        foreach ($leftXs as $stage => $x) {
            $round = $stage === 0 ? 'START' : 'RUNDA '.($stage+1);
            $svg .= '<text x="'.($x+$boxWidth/2).'" y="116" text-anchor="middle" class="round">'.$round.'</text>';
        }
        foreach ($rightXs as $stage => $x) {
            $round = $stage === 0 ? 'START' : 'RUNDA '.($stage+1);
            $svg .= '<text x="'.($x-$boxWidth/2).'" y="116" text-anchor="middle" class="round">'.$round.'</text>';
        }

        $svg .= self::connector_svg($positions, $leftXs, true, $boxWidth);
        $svg .= self::connector_svg($positions, $rightXs, false, $boxWidth);
        $svg .= self::boxes_svg($positions, $leftXs, true, $slots, $boxWidth, $boxHeight, $fontSize);
        $svg .= self::boxes_svg($positions, $rightXs, false, $slots, $boxWidth, $boxHeight, $fontSize);

        $finalY = 430.0;
        $finalW = 150.0;
        $finalH = 102.0;
        $finalX = 560.0 - $finalW/2;
        $leftLastX = $leftXs[count($leftXs)-1] + $boxWidth;
        $rightLastX = $rightXs[count($rightXs)-1] - $boxWidth;
        $svg .= '<line x1="'.$leftLastX.'" y1="'.$positions[count($positions)-1][0].'" x2="'.$finalX.'" y2="'.$finalY.'" class="line"/>';
        $svg .= '<line x1="'.$rightLastX.'" y1="'.$positions[count($positions)-1][0].'" x2="'.($finalX+$finalW).'" y2="'.$finalY.'" class="line"/>';
        $svg .= '<rect x="'.$finalX.'" y="'.($finalY-$finalH/2).'" width="'.$finalW.'" height="'.$finalH.'" rx="6" class="final"/>';
        $svg .= '<text x="560" y="'.($finalY-27).'" text-anchor="middle" class="finalTitle">FINAŁ</text>';
        $svg .= '<line x1="'.($finalX+14).'" y1="'.($finalY-8).'" x2="'.($finalX+$finalW-14).'" y2="'.($finalY-8).'" class="write"/>';
        $svg .= '<line x1="'.($finalX+14).'" y1="'.($finalY+15).'" x2="'.($finalX+$finalW-14).'" y2="'.($finalY+15).'" class="write"/>';
        $svg .= '<text x="560" y="'.($finalY+38).'" text-anchor="middle" class="small">ZWYCIĘZCA</text>';
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

        // Numer koszulki pozostaje numerem turnusowym z 0.93, niezależnym od tego,
        // czy konkretny uczestnik jest już opłacony.
        $refresh = BCS_Release_092::refresh_jersey_numbers($campId);
        if (empty($refresh['success'])) wp_die(esc_html((string)($refresh['message'] ?? 'Nie udało się odświeżyć numerów koszulek.')));

        $participants = self::paid_participants($campId);
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

            BCS_Utils::log('camp_bracket_generated_094', [
                'camp_id'=>$campId,
                'participants'=>count($participants),
                'bracket_size'=>self::next_power_of_two(count($participants)),
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
