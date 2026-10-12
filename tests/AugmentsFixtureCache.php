<?php

/**
 * Lets a single test layer extra ITEM/JOB/ABILITY rows on top of the
 * shared base fixture set (tests/fixtures/cache/) without polluting it
 * for every other test. Use this when a test needs a genuinely
 * one-off fixture -- an edge case not worth permanently adding to the
 * shared set documented in tests/fixtures/cache/README.md.
 *
 * Extra rows use the same build_item_row()/build_job_row()/
 * build_ability_row() helpers as the base set (defined in
 * generate_fixtures.php, already loaded by bootstrap.php) so they're
 * guaranteed to round-trip correctly through each class's own
 * translate_and_build(), the same way the base set is.
 */
trait AugmentsFixtureCache {

    /**
     * Runs $callback with the given extra rows layered on top of the
     * base fixture set -- keyed by itemid/jobid/abilityid, so an extra
     * row sharing an id with a base one replaces it for the duration
     * of the callback. Restores the clean base set afterward
     * regardless of whether $callback succeeds or throws.
     *
     * @param array<int, array> $items itemid => row, from build_item_row()
     * @param array<int, array> $jobs jobid => row, from build_job_row()
     * @param array<int, array> $abilities abilityid => row, from build_ability_row()
     * @param array<int, array> $personalities personalityid => row, from build_personality_row()
     */
    protected function with_extra_cache(callable $callback, array $items = [], array $jobs = [], array $abilities = [], array $personalities = []): mixed {
        generate_fixture_cache($items, $jobs, $abilities, $personalities);
        $this->reset_fixture_caches();
        try {
            return $callback();
        } finally {
            generate_fixture_cache(); // restore the clean base set
            $this->reset_fixture_caches();
        }
    }

    private function reset_fixture_caches(): void {
        // ITEM::get()/JOB::get()/ABILITY::get() all check "if ($cache
        // === null)" before loading -- resetting to null is what makes
        // the next access actually re-read the file just written,
        // rather than keep serving whatever was cached in memory from
        // before this call.
        (new ReflectionProperty(ITEM::class, 'cache'))->setValue(null, null);
        (new ReflectionProperty(JOB::class, 'cache'))->setValue(null, null);
        (new ReflectionProperty(ABILITY::class, 'cache'))->setValue(null, null);
        (new ReflectionProperty(PERSONALITY::class, 'cache'))->setValue(null, null);
    }
}
