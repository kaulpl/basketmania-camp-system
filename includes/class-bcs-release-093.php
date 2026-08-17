<?php
if (!defined('ABSPATH')) exit;

/**
 * 0.93 – numeracja koszulek zgodna z kolejnością rozmiarów stroju.
 *
 * Numer koszulki jest przypisany do zgłoszenia w ramach konkretnego turnusu,
 * ale jego kolejność wynika z rozmiaru stroju: od najmniejszego do największego.
 */
final class BCS_Release_093 {
    public static function init(): void {
        // Logika 0.93 jest używana przez odświeżanie numerów w module raportów 0.92.
    }

    public static function compare_jersey_rows(object $a, object $b): int {
        $size = BCS_Release_092::compare_shirt_sizes(
            (string)($a->shirt_size ?? ''),
            (string)($b->shirt_size ?? '')
        );
        if ($size !== 0) return $size;

        $last = strcasecmp((string)($a->child_last_name ?? ''), (string)($b->child_last_name ?? ''));
        if ($last !== 0) return $last;

        $first = strcasecmp((string)($a->child_first_name ?? ''), (string)($b->child_first_name ?? ''));
        if ($first !== 0) return $first;

        return (int)($a->id ?? 0) <=> (int)($b->id ?? 0);
    }
}
