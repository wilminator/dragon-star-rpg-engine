<?php

use PHPUnit\Framework\TestCase;

require_once INCLUDE_GAME . 'animation_manifest.php';

/**
 * The editors' list of animations comes from the committed manifest
 * (animations.json), not from anything a browser sends back.
 */
class AnimationManifestTest extends TestCase {

    public function test_it_lists_every_animation_with_what_it_needs() {
        $all = AnimationManifest::all();
        $this->assertArrayHasKey('attack_close_animation', $all);
        $this->assertSame(array_keys($all), (function ($keys) { sort($keys); return $keys; })(array_keys($all)), 'sorted');
        $spec = AnimationManifest::get('attack_close_animation');
        $this->assertCount(count($spec['images']), $spec['imageloc']);
        $this->assertIsArray($spec['sounds']);
        $this->assertIsArray($spec['times']);
    }

    public function test_an_unknown_animation_is_refused() {
        $this->expectException(InvalidArgumentException::class);
        AnimationManifest::get('</script><script>alert(1)</script>');
    }

    public function test_it_is_safe_to_print_inside_a_script() {
        $js = AnimationManifest::to_js();
        $this->assertStringNotContainsString('<', $js);
        $this->assertSame(AnimationManifest::all(), json_decode($js, true));
    }
}
