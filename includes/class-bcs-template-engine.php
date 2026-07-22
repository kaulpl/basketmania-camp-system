<?php
if (!defined('ABSPATH')) exit;

/** Centralny silnik szablonów wiadomości i dokumentów. */
final class BCS_Template_Engine {
    public const ARCHITECTURE_VERSION = '1.0';

    public static function init(): void {}
    public static function all(): array { return BCS_Templates::all(); }
    public static function get(string $group, string $key, string $fallback = ''): string { return BCS_Templates::get($group, $key, $fallback); }
    public static function render(string $content, array $vars): string { return BCS_Templates::render($content, $vars); }

    public static function render_template(string $group, string $key, array $vars = [], string $fallback = ''): string {
        return self::render(self::get($group, $key, $fallback), $vars);
    }

    public static function __callStatic(string $name, array $arguments): mixed {
        if (!is_callable([BCS_Templates::class, $name])) {
            throw new BadMethodCallException('Nieznana operacja szablonu: ' . $name);
        }
        return BCS_Templates::$name(...$arguments);
    }
}
