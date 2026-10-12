<?php
/**
 * What each fight animation needs -- images (and where they live),
 * sounds and timings -- for the ability, item and personality editors.
 * Generated from the animations themselves into animations.json by
 * utils/animation_manifest.js (run it after adding or changing an
 * animation, and commit the file).
 */
final class AnimationManifest {
    private static ?array $all = null;

    // Every animation's description, by name, sorted.
    public static function all(): array {
        if (self::$all === null) {
            $json = file_get_contents(__DIR__ . '/animations.json');
            self::$all = json_decode($json === false ? '{}' : $json, associative: true) ?: [];
            ksort(self::$all);
        }
        return self::$all;
    }

    // One animation's description. An unknown name (not in the manifest)
    // is refused rather than saved.
    public static function get(string $name): array {
        $all = self::all();
        if (!array_key_exists($name, $all)) {
            throw new InvalidArgumentException("Unknown animation: $name");
        }
        return $all[$name];
    }

    // The manifest for a page's script: safe inside <script>.
    public static function to_js(): string {
        return json_encode(self::all(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
    }
}
