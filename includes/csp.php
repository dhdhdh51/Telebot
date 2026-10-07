<?php
/**
 * Content-Security-Policy, sent from PHP so individual pages can extend it
 * (payment checkout, ad network frames) and it works without mod_headers.
 * Call again before output to replace the default.
 */
function setCsp(array $extra = [], $frameAncestors = "'self' https://web.telegram.org https://*.telegram.org") {
    $p = [
        'default-src' => ["'self'"],
        'script-src'  => ["'self'", "'unsafe-inline'", 'https://telegram.org'],
        'style-src'   => ["'self'", "'unsafe-inline'"],
        'img-src'     => ["'self'", 'data:', 'https:'],
        'media-src'   => ["'self'", 'blob:', 'https:'],
        'connect-src' => ["'self'"],
        'frame-src'   => ["'self'"],
        'base-uri'    => ["'self'"],
        'form-action' => ["'self'"],
    ];
    foreach ($extra as $dir => $sources) {
        $p[$dir] = array_values(array_unique(array_merge($p[$dir] ?? [], (array)$sources)));
    }
    $p['frame-ancestors'] = [$frameAncestors];
    $parts = [];
    foreach ($p as $dir => $src) {
        $parts[] = $dir . ' ' . implode(' ', $src);
    }
    if (!headers_sent()) {
        header('Content-Security-Policy: ' . implode('; ', $parts));
    }
}

