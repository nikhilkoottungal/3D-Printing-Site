<?php
/** Pure helpers for the sign-in rules in Admin settings > Security. */
function is_reload_request(array $server): bool
{
    $cc = strtolower((string) ($server['HTTP_CACHE_CONTROL'] ?? '')); $pr = strtolower((string) ($server['HTTP_PRAGMA'] ?? ''));
    return str_contains($cc, 'max-age=0') || str_contains($cc, 'no-cache') || str_contains($pr, 'no-cache');
}
function idle_expired(?int $lastSeen, int $minutes, ?int $now = null): bool
{
    return $minutes > 0 && $lastSeen !== null && (($now ?? time()) - $lastSeen) > $minutes * 60;
}
