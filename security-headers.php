<?php
// Cabeçalhos de segurança das PÁGINAS (usado pelo router.php; no Apache, ver .htaccess).
declare(strict_types=1);

function enviarCabecalhosSeguranca(string $caminho): void
{
    if (headers_sent()) return;
    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), payment=(), usb=(), geolocation=(self)');
    header('Cross-Origin-Opener-Policy: same-origin');

    // As páginas com Google Maps precisam da lista de domínios do Google (guia de CSP do Google Maps);
    // as demais usam uma CSP estrita, só com scripts do próprio site.
    $comMapa = (bool) preg_match('#/public/(index|home)\.html$#', $caminho);
    $csp = $comMapa
        ? "default-src 'self'; "
          . "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://*.googleapis.com https://*.gstatic.com *.google.com https://*.ggpht.com *.googleusercontent.com blob:; "
          . "img-src 'self' data: https://*.googleapis.com https://*.gstatic.com *.google.com *.googleusercontent.com; "
          . "connect-src 'self' https://*.googleapis.com *.google.com https://*.gstatic.com https://nominatim.openstreetmap.org data: blob:; "
          . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net; "
          . "font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net; "
          . "frame-src *.google.com; worker-src blob:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'"
        : "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; "
          . "font-src 'self' https://cdn.jsdelivr.net; img-src 'self' data:; connect-src 'self' https://viacep.com.br; "
          . "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'";
    header('Content-Security-Policy: ' . $csp);

    $proxy = filter_var(getenv('TRUST_PROXY') ?: 'false', FILTER_VALIDATE_BOOL);
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($proxy && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    if ($https) header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}
