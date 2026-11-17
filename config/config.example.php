<?php
// Configuração do back-end. Os valores vêm do arquivo .env (fora do Git);
// os padrões abaixo servem para o XAMPP local. Copie para config/config.php.
$env = static fn(string $k, $padrao = null) => ($v = getenv($k)) !== false && $v !== '' ? $v : $padrao;

// DB_URL (formato da Discloud e de outros provedores): mysql://USUARIO:SENHA@HOST:PORTA/BANCO
// Quando existe, tem prioridade sobre DB_HOST/DB_PORT/DB_DATABASE/DB_USER/DB_PASSWORD.
$url = $env('DB_URL') ?: $env('DATABASE_URL');
$u = $url ? (parse_url($url) ?: []) : [];

return [
    'env'   => $env('APP_ENV', 'development'),
    'debug' => filter_var($env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOL),
    'db' => [
        'host'     => $u['host'] ?? $env('DB_HOST', '127.0.0.1'),
        'port'     => (int) ($u['port'] ?? $env('DB_PORT', 3306)),
        'database' => isset($u['path']) ? ltrim($u['path'], '/') : $env('DB_DATABASE', 'ocorrencias_ambientais'),
        'user'     => isset($u['user']) ? rawurldecode($u['user']) : $env('DB_USER', 'root'),
        'password' => isset($u['pass']) ? rawurldecode($u['pass']) : $env('DB_PASSWORD', ''),
    ],
    // true só quando há um proxy na frente (Discloud): passa a ler X-Forwarded-Proto/For/Host
    'trust_proxy' => filter_var($env('TRUST_PROXY', 'false'), FILTER_VALIDATE_BOOL),
    'session_hours'        => (int) $env('SESSION_HOURS', 8),
    'session_idle_minutes' => (int) $env('SESSION_IDLE_MINUTES', 120),
    // limites contra força bruta no login (janela de 15 minutos)
    'login_max_por_email' => 5,
    'login_max_por_ip'    => 20,
    'timezone' => 'America/Sao_Paulo',
];
