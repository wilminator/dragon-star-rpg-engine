<?php

use PHPUnit\Framework\TestCase;

require_once INCLUDE_GAME . 'common.php'; // not in bootstrap.php's require chain

/**
 * Tests DehydrateTrait's own mechanics directly, via minimal
 * test-double classes -- not through any real game class. No existing
 * game class exercises the nested-object hook (CHARACTER, the only
 * current user, has no object-valued properties), so this is the only
 * coverage that hook has until PARTY/GROUP/FIGHT come to depend on it.
 *
 * Deliberately follows this codebase's usual convention of testing
 * shared traits only through their using classes (see DbStoreTrait,
 * untested standalone, always exercised via TEAM/ITEM/etc) -- these
 * test doubles play that same role, just with no real class to borrow
 * for it yet.
 */

class DehydrateFlatDouble implements DehydrateInterface {
    use DehydrateTrait;
    const DEHYDRATE_VERSION = 1;
    private const DEHYDRATE_PROPERTIES = ['label', 'count'];
    public string $label = '';
    public int $count = 0;
}

class DehydrateNestedItemDouble implements DehydrateInterface {
    use DehydrateTrait;
    const DEHYDRATE_VERSION = 1;
    private const DEHYDRATE_PROPERTIES = ['label'];
    public string $label = '';
}

class DehydrateNestedContainerDouble implements DehydrateInterface {
    use DehydrateTrait;
    const DEHYDRATE_VERSION = 1;
    private const DEHYDRATE_PROPERTIES = ['name'];
    public string $name = '';
    /** @var DehydrateNestedItemDouble[] */
    public array $items = [];

    protected function dehydrate_nested(): array {
        return ['items' => array_map(fn($item) => $item->dehydrate(), $this->items)];
    }

    protected static function hydrate_nested(self $object, array $data): void {
        $object->items = array_map(fn($d) => DehydrateNestedItemDouble::hydrate($d), $data['items']);
    }
}

class DehydrateTraitTest extends TestCase {

    // --- flat (no nested objects) ---

    public function test_dehydrate_projects_every_listed_property() {
        $double = new DehydrateFlatDouble();
        $double->label = 'hello';
        $double->count = 5;

        $data = $double->dehydrate();

        $this->assertSame('hello', $data['label']);
        $this->assertSame(5, $data['count']);
    }

    public function test_dehydrate_includes_the_using_classs_own_version() {
        $double = new DehydrateFlatDouble();

        $data = $double->dehydrate();

        $this->assertSame(DehydrateFlatDouble::DEHYDRATE_VERSION, $data['version']);
    }

    public function test_hydrate_reverses_dehydrate_for_a_flat_double() {
        $double = new DehydrateFlatDouble();
        $double->label = 'hello';
        $double->count = 5;

        $rehydrated = DehydrateFlatDouble::hydrate($double->dehydrate());

        $this->assertSame('hello', $rehydrated->label);
        $this->assertSame(5, $rehydrated->count);
    }

    public function test_hydrate_returns_an_instance_of_the_using_class() {
        $rehydrated = DehydrateFlatDouble::hydrate(['label' => 'x', 'count' => 1, 'version' => 1]);

        $this->assertInstanceOf(DehydrateFlatDouble::class, $rehydrated);
    }

    // --- nested objects (dehydrate_nested()/hydrate_nested() hooks) ---

    public function test_dehydrate_nested_embeds_each_items_own_dehydrate_output() {
        $container = new DehydrateNestedContainerDouble();
        $container->name = 'Container';
        $item = new DehydrateNestedItemDouble();
        $item->label = 'first';
        $container->items = [$item];

        $data = $container->dehydrate();

        $this->assertSame('Container', $data['name']);
        $this->assertSame('first', $data['items'][0]['label']);
        $this->assertSame(DehydrateNestedItemDouble::DEHYDRATE_VERSION, $data['items'][0]['version']);
    }

    public function test_hydrate_nested_reconstructs_real_instances_not_arrays() {
        $container = new DehydrateNestedContainerDouble();
        $container->name = 'Container';
        $item1 = new DehydrateNestedItemDouble();
        $item1->label = 'first';
        $item2 = new DehydrateNestedItemDouble();
        $item2->label = 'second';
        $container->items = [$item1, $item2];

        $rehydrated = DehydrateNestedContainerDouble::hydrate($container->dehydrate());

        $this->assertCount(2, $rehydrated->items);
        $this->assertInstanceOf(DehydrateNestedItemDouble::class, $rehydrated->items[0]);
        $this->assertSame('first', $rehydrated->items[0]->label);
        $this->assertSame('second', $rehydrated->items[1]->label);
    }

    public function test_nested_round_trip_survives_actual_json_encoding() {
        // Simulates real DB storage: dehydrate() -> json_encode() ->
        // (stored, fetched later) -> json_decode() -> hydrate().
        $container = new DehydrateNestedContainerDouble();
        $container->name = 'Container';
        $item = new DehydrateNestedItemDouble();
        $item->label = 'first';
        $container->items = [$item];

        $json = json_encode($container->dehydrate());
        $decoded = json_decode($json, true);
        $rehydrated = DehydrateNestedContainerDouble::hydrate($decoded);

        $this->assertSame('Container', $rehydrated->name);
        $this->assertInstanceOf(DehydrateNestedItemDouble::class, $rehydrated->items[0]);
        $this->assertSame('first', $rehydrated->items[0]->label);
    }

    public function test_dehydrate_nested_defaults_to_a_no_op_when_not_overridden() {
        // DehydrateFlatDouble never overrides dehydrate_nested() --
        // confirms the trait's default doesn't add anything unexpected
        // to a flat double's output.
        $double = new DehydrateFlatDouble();

        $data = $double->dehydrate();

        $this->assertSame(['label', 'count', 'version'], array_keys($data));
    }
}
