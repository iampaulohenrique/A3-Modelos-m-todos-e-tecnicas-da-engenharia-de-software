<?php
// Carregado no início de todo endpoint da API.
// Lê .env e a configuração, carrega as classes, aplica cabeçalhos de segurança,
// confere a origem das requisições que alteram dados e transforma erros em JSON.

declare(strict_types=1);

// ---------- .env (variáveis de ambiente fora do código e do Git) ----------
require_once __DIR__ . '/env.php';

$configFile = __DIR__ . '/../config/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['erro' => 'Crie o arquivo config/config.php a partir de config/config.example.php.']);
    exit;
}
$GLOBALS['APP_CONFIG'] = require $configFile;
date_default_timezone_set($GLOBALS['APP_CONFIG']['timezone'] ?? 'America/Sao_Paulo');

function config(string $chave, $padrao = null)
{
    return $GLOBALS['APP_CONFIG'][$chave] ?? $padrao;
}

// Erros nunca vão para a tela (só para o log), mesmo se o php.ini estiver errado
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// ---------- Autoload restrito (sem inclusão de arquivo a partir de entrada externa) ----------
spl_autoload_register(function (string $classe): void {
    if (!preg_match('/^[A-Z][A-Za-z0-9]{1,60}$/', $classe)) return;  // bloqueia ../, \, nomes estranhos
    foreach ([__DIR__ . "/$classe.php", __DIR__ . "/../api/controllers/$classe.php"] as $arquivo) {
        if (is_file($arquivo)) {
            require $arquivo;
            return;
        }
    }
});

// ---------- Cabeçalhos de segurança da API ----------
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cross-Origin-Resource-Policy: same-origin');
header_remove('X-Powered-By');
if (Request::https()) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// ---------- Erros ----------
set_exception_handler(function (Throwable $e): void {
    if ($e instanceof HttpException) {
        Response::erro($e->getMessage(), $e->status, $e->campos, $e->codigo);
    }
    if ($e instanceof PDOException) {
        $mapeado = DbErro::mapear($e);
        if ($mapeado) {
            Response::erro($mapeado->getMessage(), $mapeado->status, $mapeado->campos, $mapeado->codigo);
        }
    }
    error_log((string) $e);
    $msg = config('debug') ? $e->getMessage() : 'Erro interno no servidor. Tente de novo em instantes.';
    Response::erro($msg, 500);
});

// ---------- Origem: só o próprio site pode enviar POST/PUT/DELETE ----------
Request::exigirMesmaOrigem();
