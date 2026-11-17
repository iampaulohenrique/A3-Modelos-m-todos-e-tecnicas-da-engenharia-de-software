<?php
// Diagnóstico da conexão com o banco. Rode no console:  php database/diagnostico.php
// Mostra o que está faltando sem expor a senha.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$ok = fn($b) => $b ? "OK " : "FALHA";
echo "== Diagnóstico do banco ==\n";
echo "PHP: " . PHP_VERSION . "  (" . $ok(version_compare(PHP_VERSION, '8.1.0', '>=')) . " precisa 8.1+)\n";
echo "Extensão pdo_mysql: " . $ok(extension_loaded('pdo_mysql')) . "\n";
echo "Extensão mbstring:  " . $ok(extension_loaded('mbstring')) . "\n";

$envFile = __DIR__ . '/../.env';
echo "Arquivo .env: " . (is_file($envFile) ? "encontrado" : "NÃO encontrado na raiz do projeto") . "\n";
require __DIR__ . '/../src/env.php';
$cfg = require __DIR__ . '/../config/config.php';
$db = $cfg['db'];
echo "DB_URL definida: " . ((getenv('DB_URL') ?: getenv('DATABASE_URL')) ? 'sim' : 'não (usando DB_HOST etc.)') . "\n";
echo "TRUST_PROXY: " . (($cfg['trust_proxy'] ?? false) ? 'true' : 'false') . "\n";
printf("Conectando em: host=%s porta=%d banco=%s usuário=%s senha=%s\n",
    $db['host'], $db['port'], $db['database'], $db['user'], $db['password'] === '' ? '(vazia)' : str_repeat('*', 8));

$ip = gethostbyname($db['host']);
$resolveu = $ip !== $db['host'] || filter_var($db['host'], FILTER_VALIDATE_IP);
echo "Resolver o host: " . ($resolveu ? "OK  ($ip)" : "FALHA (nome não encontrado: VLAN desligada ou host errado)") . "\n";

$sock = @fsockopen($db['host'], (int) $db['port'], $errno, $errstr, 5);
echo "Abrir a porta {$db['port']}: " . ($sock ? "OK" : "FALHA ($errstr)") . "\n";
if ($sock) fclose($sock);

if (!extension_loaded('pdo_mysql')) {
    echo "\n→ Sem pdo_mysql o PHP não fala com o MySQL. Peça ao suporte da Discloud para habilitar a extensão.\n";
    exit(1);
}
try {
    $pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['database']),
        $db['user'], $db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
    echo "Login no MySQL: OK  (versão " . $pdo->query('SELECT VERSION()')->fetchColumn() . ")\n";
    $t = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    echo "Tabelas: " . ($t ? implode(', ', $t) : "nenhuma → rode: php database/importar.php") . "\n";
} catch (PDOException $e) {
    echo "Login no MySQL: FALHA\n  " . $e->getMessage() . "\n";
    $m = $e->getMessage();
    if (str_contains($m, 'Access denied')) echo "→ Usuário ou senha errados na DB_URL (caracteres especiais da senha precisam de %XX).\n";
    elseif (str_contains($m, 'Unknown database')) echo "→ O nome do banco no fim da DB_URL não existe.\n";
    elseif (str_contains($m, 'getaddrinfo') || str_contains($m, 'Name or service')) echo "→ Host não encontrado: ligue a VLAN no app e deixe app e banco no mesmo cluster/projeto.\n";
    elseif (str_contains($m, 'refused') || str_contains($m, 'timed out')) echo "→ Porta errada ou banco desligado/fora da rede privada.\n";
    exit(1);
}
