<?php
// Roteador para o servidor embutido do PHP (php -S), que NÃO lê .htaccess.
// Uso:  php -S localhost:8000 router.php   (na Discloud: php -S 0.0.0.0:8080 router.php)
// Bloqueia pastas internas e arquivos sensíveis e aplica os cabeçalhos de segurança.
declare(strict_types=1);

$caminho = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');

$bloqueado = preg_match('#(^|/)\.#', $caminho)                                   // .env, .git, .htaccess
    || preg_match('#^/(config|src|database|backups|api/controllers|vendor|node_modules)(/|$)#i', $caminho)
    || preg_match('#\.(sql|md|ini|log|lock|json|example|bak|sh)$#i', $caminho)
    || in_array($caminho, ['/router.php', '/security-headers.php', '/discloud.config'], true);

if ($bloqueado) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Não encontrado';
    return true;
}

require_once __DIR__ . '/src/env.php';
require __DIR__ . '/security-headers.php';
enviarCabecalhosSeguranca($caminho);

// Endereços limpos (mesmas regras do .htaccess)
$limpos = ['/' => 'home', '/mapa' => 'index', '/entrar' => 'login', '/cadastro' => 'register', '/perfil' => 'perfil', '/admin' => 'admin'];
$antigos = array_flip($limpos);
if (preg_match('#^(?:/public)?/(home|index|login|register|perfil|admin)\.html$#', $caminho, $m) || preg_match('#^/public/?$#', $caminho)) {
    $destino = $antigos[$m[1] ?? 'home'];
    $query = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY);
    header('Location: ' . $destino . ($query ? '?' . $query : ''), true, 301);
    return true;
}
if (isset($limpos[$caminho])) {
    $caminho = '/public/' . $limpos[$caminho] . '.html';
    enviarCabecalhosSeguranca($caminho);   // CSP do Google Maps para mapa e página inicial
}

// Arquivos estáticos: o próprio roteador entrega, para os cabeçalhos acima valerem
// (quando o roteador devolve false, o servidor embutido descarta os cabeçalhos).
$arquivo = realpath(__DIR__ . $caminho);
$raiz = realpath(__DIR__);
if ($arquivo && str_starts_with($arquivo, $raiz . DIRECTORY_SEPARATOR) && is_file($arquivo)
    && strtolower(pathinfo($arquivo, PATHINFO_EXTENSION)) !== 'php') {
    $tipos = [
        'html' => 'text/html; charset=utf-8', 'css' => 'text/css; charset=utf-8',
        'js' => 'text/javascript; charset=utf-8', 'svg' => 'image/svg+xml', 'png' => 'image/png',
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'ico' => 'image/x-icon',
        'woff2' => 'font/woff2', 'txt' => 'text/plain; charset=utf-8',
    ];
    $ext = strtolower(pathinfo($arquivo, PATHINFO_EXTENSION));
    if (!isset($tipos[$ext])) {
        http_response_code(404);
        return true;
    }
    header('Content-Type: ' . $tipos[$ext]);
    header('Content-Length: ' . filesize($arquivo));
    readfile($arquivo);
    return true;
}
return false; // .php: o servidor embutido executa (a API aplica os próprios cabeçalhos)
