<?php
class AnimationEffect implements JsonSerializable {
    public string $animation;
    public array $images;
    public array $sounds;
    public array $times;

    public function __construct(
        string $animation = 'static_individual_impact',
        array $images = [],
        array $sounds = [],
        array $times = [100, 100]
    ) {
        $this->animation = $animation;
        $this->images = $images;
        $this->sounds = $sounds;
        $this->times = array_map(fn($x)=>(int)$x, $times);
    }

    public function jsonSerialize(): array {
        return [
            'animation' => $this->animation,
            'images' => $this->images,
            'sounds' => $this->sounds,
            'times' => $this->times,
        ];
    }

    # This is a temporary helper function that converts the data into a format
    # more easily used by the client. It will be removed once the client is updated to use the new format.
    public function to_client_js(): array {
        return [$this->animation, ['images' => $this->images, 'sounds' => $this->sounds, 'times' => $this->times]];
    }

    public function to_playback_action(
        int $party_index, 
        int $group_index, 
        int $char_index, 
        int $target_party_index, 
        int $target_group_index, 
        int $target_char_index, 
        int $range = 0,
        array $effects = []): array {
        return [
            0,  // Parallel-execution flag for the client. Always 0 here -- forcing
                // sequential completion is done via a separate, standalone barrier
                // action (a single-element array containing 1), not this flag.
            'invoke_animation',
            $this->animation,
            ['party' => $party_index, 'group' => $group_index, 'character' => $char_index],
            ['party' => $target_party_index, 'group' => $target_group_index, 'character' => $target_char_index],
            $range,
            $effects,
            ['images' => $this->images, 'sounds' => $this->sounds, 'times' => $this->times],
        ];
    }

    public static function from_array(?array $data): self {
        if ($data === null) {
            return new self();
        }
        return new self(
            animation: $data['animation'] ?? 'static_individual_impact',
            images: $data['images'] ?? [],
            sounds: $data['sounds'] ?? [],
            times: $data['times'] ?? [100, 100],
        );
    }

    public static function from_json(?string $json): self {
        if ($json === null) {
            return new self();
        }
        return self::from_array(json_decode($json, true));
    }
}
