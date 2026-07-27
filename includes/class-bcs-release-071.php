<?php
if (!defined('ABSPATH')) exit;

/**
 * Wersja 0.71:
 * - sekcja dowodowa umowy ma jedną kolumnę i dokładnie dwa wiersze,
 * - pierwszy wiersz zawiera potwierdzenie Rodzica / Opiekuna,
 * - drugi wiersz zawiera potwierdzenie Organizatora,
 * - ten sam układ jest stosowany w PDF oraz w podglądach HTML umowy V2.
 */
final class BCS_Release_071 {
    private const STYLE_MARKER = 'bcs-evidence-layout-071';

    public static function init(): void {
        self::replace_preview_handlers();
    }

    private static function replace_preview_handlers(): void {
        if (!class_exists('BCS_Agreement_PDF_V2')) return;

        remove_action('admin_post_bcs_agreement_view', ['BCS_Agreement_PDF_V2', 'render_agreement_view'], 0);
        remove_action('admin_post_nopriv_bcs_agreement_view', ['BCS_Agreement_PDF_V2', 'render_agreement_view'], 0);
        remove_action('admin_post_bcs_agreement_version_preview_054', ['BCS_Agreement_PDF_V2', 'render_version_preview']);

        add_action('admin_post_bcs_agreement_view', [__CLASS__, 'render_agreement_view'], 0);
        add_action('admin_post_nopriv_bcs_agreement_view', [__CLASS__, 'render_agreement_view'], 0);
        add_action('admin_post_bcs_agreement_version_preview_054', [__CLASS__, 'render_version_preview']);
    }

    public static function render_agreement_view(): void {
        ob_start([__CLASS__, 'buffer_preview_html']);
        BCS_Agreement_PDF_V2::render_agreement_view();
    }

    public static function render_version_preview(): void {
        ob_start([__CLASS__, 'buffer_preview_html']);
        BCS_Agreement_PDF_V2::render_version_preview();
    }

    public static function buffer_preview_html(string $html): string {
        return self::normalize_evidence_layout($html);
    }

    private static function normalized(string $text): string {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return mb_strtoupper((string)preg_replace('/\s+/u', ' ', trim($text)), 'UTF-8');
    }

    private static function class_query(string $class): string {
        return "//*[contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')]";
    }

    private static function load_document(string $html): ?DOMDocument {
        if (!class_exists('DOMDocument')) return null;
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $ok = $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $ok ? $dom : null;
    }

    private static function save_document(DOMDocument $dom): string {
        $html = (string)$dom->saveHTML();
        return preg_replace('/^<\?xml[^>]*>\s*/i', '', $html) ?? $html;
    }

    /** @return DOMElement[] */
    private static function row_cells(DOMElement $row): array {
        $cells = [];
        foreach ($row->childNodes as $child) {
            if (!$child instanceof DOMElement) continue;
            $tag = strtolower($child->tagName);
            if ($tag === 'td' || $tag === 'th') $cells[] = $child;
        }
        return $cells;
    }

    private static function contains_parent_label(string $text): bool {
        $text = self::normalized($text);
        return str_contains($text, 'RODZIC') || str_contains($text, 'OPIEKUN');
    }

    private static function contains_organizer_label(string $text): bool {
        return str_contains(self::normalized($text), 'ORGANIZATOR');
    }

    /** @return DOMElement[] */
    private static function table_rows(DOMXPath $xpath, DOMElement $table): array {
        $rows = [];
        foreach ($xpath->query('.//tr', $table) as $row) {
            if ($row instanceof DOMElement && self::row_cells($row)) $rows[] = $row;
        }
        return $rows;
    }

    /**
     * @param DOMElement[] $sources
     */
    private static function append_sources(DOMDocument $dom, DOMElement $target, array $sources): void {
        foreach ($sources as $source) {
            $fragment = $dom->createElement('div');
            $classes = ['bcs-v2-evidence-fragment'];
            if (strtolower($source->tagName) === 'th') $classes[] = 'bcs-v2-evidence-fragment-heading';
            $fragment->setAttribute('class', implode(' ', $classes));

            if ($source->hasChildNodes()) {
                foreach (iterator_to_array($source->childNodes) as $child) {
                    $fragment->appendChild($child->cloneNode(true));
                }
            } else {
                $fragment->appendChild($dom->createTextNode((string)$source->textContent));
            }
            $target->appendChild($fragment);
        }
    }

    /**
     * @param DOMElement[] $sources
     */
    private static function append_row(
        DOMDocument $dom,
        DOMElement $tbody,
        string $role,
        array $sources
    ): void {
        $row = $dom->createElement('tr');
        $row->setAttribute('class', 'bcs-v2-evidence-row bcs-v2-evidence-row-'.$role);
        $row->setAttribute('data-evidence-role', $role);

        $cell = $dom->createElement('td');
        $cell->setAttribute('class', 'bcs-v2-evidence-cell bcs-v2-evidence-cell-'.$role);
        $cell->setAttribute('data-evidence-role', $role);
        self::append_sources($dom, $cell, $sources);

        $row->appendChild($cell);
        $tbody->appendChild($row);
    }

    /**
     * @return array{parent: DOMElement[], organizer: DOMElement[]}|null
     */
    private static function extract_blocks(DOMXPath $xpath, DOMElement $table): ?array {
        $rows = self::table_rows($xpath, $table);
        if (!$rows) return null;

        $cellsByRow = [];
        $maxColumns = 0;
        foreach ($rows as $row) {
            $cells = self::row_cells($row);
            $cellsByRow[] = $cells;
            $maxColumns = max($maxColumns, count($cells));
        }

        // Układ już pionowy albo zbudowany z pojedynczych wierszy.
        if ($maxColumns === 1) {
            $parent = [];
            $organizer = [];
            foreach ($cellsByRow as $cells) {
                $cell = $cells[0] ?? null;
                if (!$cell instanceof DOMElement) continue;
                $text = (string)$cell->textContent;
                if (self::contains_parent_label($text)) $parent[] = $cell;
                elseif (self::contains_organizer_label($text)) $organizer[] = $cell;
            }
            if ($parent && $organizer) return ['parent'=>$parent, 'organizer'=>$organizer];
            return null;
        }

        // Dotychczasowy układ poziomy: każda strona podpisu jest osobną kolumną.
        $columns = [];
        for ($column = 0; $column < $maxColumns; $column++) {
            $columns[$column] = [];
            foreach ($cellsByRow as $cells) {
                if (isset($cells[$column]) && $cells[$column] instanceof DOMElement) {
                    $columns[$column][] = $cells[$column];
                }
            }
        }

        $parentIndex = null;
        $organizerIndex = null;
        foreach ($columns as $index => $cells) {
            $text = implode(' ', array_map(static fn(DOMElement $cell): string => (string)$cell->textContent, $cells));
            if ($parentIndex === null && self::contains_parent_label($text)) $parentIndex = $index;
            if ($organizerIndex === null && self::contains_organizer_label($text)) $organizerIndex = $index;
        }

        // Historyczny szablon miał Organizatora po lewej, a Rodzica po prawej.
        if ($parentIndex === null && count($columns) >= 2) $parentIndex = 1;
        if ($organizerIndex === null && count($columns) >= 1) $organizerIndex = 0;
        if ($parentIndex === $organizerIndex) return null;

        $parent = $columns[$parentIndex] ?? [];
        $organizer = $columns[$organizerIndex] ?? [];
        return ($parent && $organizer) ? ['parent'=>$parent, 'organizer'=>$organizer] : null;
    }

    private static function inject_style(DOMDocument $dom): void {
        $css = '
            .bcs-v2-evidence-table{width:100%;table-layout:fixed;border-collapse:collapse;margin:6px 0 0;page-break-inside:avoid;background:#fff}
            .bcs-v2-evidence-table .bcs-v2-evidence-row{page-break-inside:avoid}
            .bcs-v2-evidence-table .bcs-v2-evidence-cell{width:100%;padding:10px 12px;vertical-align:top;border:1px solid #f0a36f;background:#fff;color:#172033}
            .bcs-v2-evidence-table .bcs-v2-evidence-row-organizer .bcs-v2-evidence-cell{border-top:1.4px solid #f97316}
            .bcs-v2-evidence-fragment{margin:0 0 4px;padding:0}
            .bcs-v2-evidence-fragment:last-child{margin-bottom:0}
            .bcs-v2-evidence-fragment-heading{font-size:11pt;font-weight:700;color:#c2410c}
        ';

        foreach ($dom->getElementsByTagName('style') as $style) {
            if (!$style instanceof DOMElement) continue;
            if ($style->getAttribute('id') !== 'bcs-agreement-v2-style') continue;
            if (str_contains((string)$style->textContent, self::STYLE_MARKER)) return;
            $style->appendChild($dom->createTextNode("\n/* ".self::STYLE_MARKER." */\n".$css));
            return;
        }

        $style = $dom->createElement('style');
        $style->setAttribute('id', self::STYLE_MARKER);
        $style->appendChild($dom->createTextNode($css));
        $head = $dom->getElementsByTagName('head')->item(0);
        if ($head instanceof DOMNode) $head->appendChild($style);
    }

    public static function normalize_evidence_layout(string $html): string {
        if (trim($html) === '' || !str_contains(self::normalized(strip_tags($html)), 'POTWIERDZENIE')) return $html;
        $dom = self::load_document($html);
        if (!$dom) return $html;
        $xpath = new DOMXPath($dom);

        $evidence = $xpath->query(self::class_query('bcs-v2-evidence'))->item(0);
        if (!$evidence instanceof DOMElement) {
            foreach (['proof', 'bcs-proof-page', 'bcs-proof-page-069'] as $class) {
                $candidate = $xpath->query(self::class_query($class))->item(0);
                if ($candidate instanceof DOMElement) {
                    $evidence = $candidate;
                    break;
                }
            }
        }
        if (!$evidence instanceof DOMElement) return $html;

        $table = null;
        foreach ($xpath->query('.//table', $evidence) as $candidate) {
            if (!$candidate instanceof DOMElement) continue;
            $text = (string)$candidate->textContent;
            if (self::contains_parent_label($text) && self::contains_organizer_label($text)) {
                $table = $candidate;
                break;
            }
        }
        if (!$table instanceof DOMElement || !$table->parentNode) return $html;

        $blocks = self::extract_blocks($xpath, $table);
        if (!$blocks) return $html;

        $newTable = $dom->createElement('table');
        $newTable->setAttribute('class', 'bcs-v2-evidence-table');
        $newTable->setAttribute('data-layout', 'two-rows-one-column');
        $tbody = $dom->createElement('tbody');
        self::append_row($dom, $tbody, 'parent', $blocks['parent']);
        self::append_row($dom, $tbody, 'organizer', $blocks['organizer']);
        $newTable->appendChild($tbody);

        $table->parentNode->replaceChild($newTable, $table);
        self::inject_style($dom);
        return self::save_document($dom);
    }
}
