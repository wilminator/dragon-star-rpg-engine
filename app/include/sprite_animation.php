<?php
class SpriteAnimation implements JsonSerializable {
    public array $images;
    public array $times;

    public function __construct(array $images = [], array $times = []) {
        $this->images = $images;
        $this->times = $times;
    }

    public function jsonSerialize(): array {
        return [
            'images' => $this->images,
            'times' => $this->times,
        ];
    }

    public static function from_array(?array $data): self {
        if ($data === null) {
            return new self();
        }
        return new self(
            images: $data['images'] ?? [],
            times: $data['times'] ?? [],
        );
    }

    public static function from_json(?string $json): self {
        return self::from_array($json === null ? null : json_decode($json, true));
    }
}