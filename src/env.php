<?php
// Carrega o arquivo .env (variáveis de ambiente fora do código e do Git).
// Usado pela API (bootstrap.php) e pelo router.php. Variável real do servidor tem prioridade.
declare(strict_types=1);

(function (string $arquivo): void {
    if (!is_file($arquivo) || !is_readable($arquivo)) return;
    foreach (file($arquivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linha) {
        $linha = trim($linha);
        if ($linha === '' || $linha[0] === '#' || !str_contains($linha, '=')) continue;
        [$k, $v] = array_map('trim', explode('=', $linha, 2));
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $k)) continue;
        $v = preg_replace('/\s+#.*$/', '', $v);            // comentário no fim da linha
        $v = trim($v, "\"'");
        if (getenv($k) === false) {
            putenv("$k=$v");
            $_ENV[$k] = $v;
        }
    }
})(__DIR__ . '/../.env');
