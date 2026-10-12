<?php
function make_index_key(int ...$indexes): string {
    return implode(';', $indexes);
}

function split_index_key(string $key): array {
    $parts = explode(';', $key);
    if (!array_all($parts, fn($p) => is_numeric($p))) {
        log_error("Malformed index key: $key");
    }
    return array_map(fn(string $s) => (int)$s, $parts);
}
