<?php
if (!defined('ABSPATH')) exit;

final class BCS_Release_055 {
    public static function init(): void {}

    private static function normalized(string $text): string {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return mb_strtoupper((string)preg_replace('/\s+/u', ' ', trim($text)), 'UTF-8');
    }

    private static function has_class(DOMElement $element, string $class): bool {
        $classes = preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [];
        return in_array($class, $classes, true);
    }

    private static function add_class(DOMElement $element, string $class): void {
        $classes = preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [];
        if (!in_array($class, $classes, true)) $classes[] = $class;
        $element->setAttribute('class', trim(implode(' ', array_filter($classes))));
    }

    private static function remove_class(DOMElement $element, string $class): void {
        $classes = preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [];
        $classes = array_values(array_filter($classes, static fn(string $item): bool => $item !== $class));
        if ($classes) $element->setAttribute('class', implode(' ', $classes));
        else $element->removeAttribute('class');
    }

    private static function is_attachment_reference(string $text): bool {
        return str_starts_with($text, 'ZAŁĄCZNIK NR 1')
            || str_starts_with($text, 'ZALACZNIK NR 1');
    }

    private static function is_attachment_heading(string $text): bool {
        return self::is_attachment_reference($text)
            && str_contains($text, 'KARTA KWALIFIKACYJNA UCZESTNIKA WYPOCZYNKU');
    }

    private static function append_style(DOMElement $element, string $declaration): void {
        $style = trim($element->getAttribute('style'));
        if ($style !== '' && !str_ends_with($style, ';')) $style .= ';';
        $element->setAttribute('style', $style.$declaration);
    }

    private static function add_document_css(DOMDocument $dom): void {
        $xpath = new DOMXPath($dom);
        if ($xpath->query('//*[@id="bcs-agreement-style-055"]')->length) return;

        $style = $dom->createElement('style');
        $style->setAttribute('id', 'bcs-agreement-style-055');
        $style->appendChild($dom->createTextNode(
            '.bcs-attachment-start-055{page-break-before:always!important;break-before:page!important;margin-top:0!important;padding-top:0!important}'
            .'.bcs-attachment-reference-055{page-break-before:auto!important;break-before:auto!important}'
        ));

        $head = $dom->getElementsByTagName('head')->item(0);
        if ($head) {
            $head->appendChild($style);
            return;
        }

        $body = $dom->getElementsByTagName('body')->item(0);
        if ($body) $body->insertBefore($style, $body->firstChild);
        else $dom->appendChild($style);
    }

    private static function fallback(string $html): string {
        $html = preg_replace_callback(
            '~<p\b([^>]*)>(.*?)</p>~isu',
            static function (array $match): string {
                $text = self::normalized(wp_strip_all_tags($match[2]));
                if (!self::is_attachment_reference($text)) return $match[0];

                $attrs = preg_replace(
                    '/\sclass=("|\')([^"\']*)\1/iu',
                    static function (array $class_match): string {
                        $classes = preg_split('/\s+/', trim($class_match[2])) ?: [];
                        $classes = array_values(array_filter(
                            $classes,
                            static fn(string $class): bool => !in_array($class, ['bcs-page-break-before','bcs-keep-with-next'], true)
                        ));
                        $classes[] = 'bcs-attachment-reference-055';
                        return ' class="'.esc_attr(implode(' ', array_unique($classes))).'"';
                    },
                    $match[1],
                    1,
                    $count
                );
                if (!$count) $attrs .= ' class="bcs-attachment-reference-055"';
                return '<p'.$attrs.'>'.$match[2].'</p>';
            },
            $html
        );

        $html = preg_replace_callback(
            '~<h([1-3])\b([^>]*)>(.*?)</h\1>~isu',
            static function (array $match): string {
                $text = self::normalized(wp_strip_all_tags($match[3]));
                if (!self::is_attachment_heading($text)) return $match[0];

                $attrs = $match[2];
                if (preg_match('/\sclass=("|\')([^"\']*)\1/iu', $attrs, $class_match)) {
                    $classes = preg_split('/\s+/', trim($class_match[2])) ?: [];
                    $classes = array_unique(array_merge($classes, ['bcs-page-break-before','bcs-keep-with-next','bcs-attachment-start-055']));
                    $attrs = preg_replace('/\sclass=("|\')([^"\']*)\1/iu', ' class="'.esc_attr(implode(' ', $classes)).'"', $attrs, 1);
                } else {
                    $attrs .= ' class="bcs-page-break-before bcs-keep-with-next bcs-attachment-start-055"';
                }

                if (preg_match('/\sstyle=("|\')([^"\']*)\1/iu', $attrs, $style_match)) {
                    $style = rtrim(trim($style_match[2]), ';').';page-break-before:always;break-before:page;margin-top:0;padding-top:0;';
                    $attrs = preg_replace('/\sstyle=("|\')([^"\']*)\1/iu', ' style="'.esc_attr($style).'"', $attrs, 1);
                } else {
                    $attrs .= ' style="page-break-before:always;break-before:page;margin-top:0;padding-top:0"';
                }

                return '<h'.$match[1].$attrs.'>'.$match[3].'</h'.$match[1].'>';
            },
            (string)$html,
            1
        );

        return (string)$html;
    }

    public static function force_attachment_page(string $html): string {
        if (trim($html) === '' || (!str_contains(self::normalized(wp_strip_all_tags($html)), 'ZAŁĄCZNIK NR 1')
            && !str_contains(self::normalized(wp_strip_all_tags($html)), 'ZALACZNIK NR 1'))) {
            return $html;
        }

        if (!class_exists('DOMDocument')) return self::fallback($html);

        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) return self::fallback($html);

        $xpath = new DOMXPath($dom);
        $heading_found = false;

        foreach ($xpath->query('//p') as $node) {
            if (!$node instanceof DOMElement) continue;
            $text = self::normalized($node->textContent ?? '');
            if (!self::is_attachment_reference($text)) continue;

            self::remove_class($node, 'bcs-page-break-before');
            self::remove_class($node, 'bcs-keep-with-next');
            self::add_class($node, 'bcs-attachment-reference-055');
        }

        foreach ($xpath->query('//h1|//h2|//h3') as $node) {
            if (!$node instanceof DOMElement) continue;
            $text = self::normalized($node->textContent ?? '');
            if (!self::is_attachment_heading($text)) continue;

            self::add_class($node, 'bcs-page-break-before');
            self::add_class($node, 'bcs-keep-with-next');
            self::add_class($node, 'bcs-attachment-start-055');
            self::append_style($node, 'page-break-before:always;break-before:page;margin-top:0;padding-top:0;');
            $heading_found = true;
            break;
        }

        if (!$heading_found) return self::fallback($html);

        self::add_document_css($dom);
        $result = $dom->saveHTML();
        $result = preg_replace('/^<\?xml[^>]+>\s*/i', '', (string)$result);
        return trim((string)$result);
    }
}
