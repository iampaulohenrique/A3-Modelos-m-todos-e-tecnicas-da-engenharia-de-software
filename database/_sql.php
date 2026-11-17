<?php
// Utilidades de linha de comando para rodar arquivos .sql pelo PDO
// (sem o cliente "mysql", que nem sempre existe no servidor, como na Discloud).
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../src/bootstrap.php';
restore_exception_handler();
header_remove();

// Divide um arquivo SQL em comandos, respeitando DELIMITER (gatilhos, procedimentos, funções)
// e ignorando comentários, CREATE DATABASE e USE (o banco já vem criado pelo provedor).
function sql_comandos(string $sql): array
{
    $comandos = [];
    $delim = ';';
    $atual = '';
    foreach (preg_split('/\R/', $sql) as $linha) {
        $limpa = trim($linha);
        if ($atual === '' && ($limpa === '' || str_starts_with($limpa, '--') || str_starts_with($limpa, '#'))) continue;
        if (preg_match('/^DELIMITER\s+(\S+)/i', $limpa, $m)) {
            $delim = $m[1];
            continue;
        }
        $atual .= $linha . "\n";
        if (str_ends_with(rtrim($linha), $delim)) {
            $cmd = trim(substr(rtrim($atual), 0, -strlen($delim)));
            $atual = '';
            if ($cmd === '') continue;
            if (preg_match('/^(CREATE\s+DATABASE|USE\s)/i', $cmd)) continue;
            // CREATE DATABASE de várias linhas termina na linha seguinte
            if (preg_match('/^CHARACTER\s+SET/i', $cmd)) continue;
            $comandos[] = $cmd;
        }
    }
    if (trim($atual) !== '') $comandos[] = trim($atual);
    return $comandos;
}

function sql_executar(PDO $db, string $sql, bool $mostrar = true, bool $opcional = false): int
{
    $n = 0;
    foreach (sql_comandos($sql) as $cmd) {
        try {
            $db->exec($cmd);
            $n++;
        } catch (PDOException $e) {
            $codigo = (int) ($e->errorInfo[1] ?? 0);
            if ($opcional) return -1;
            fwrite(STDERR, "\nERRO ao executar:\n" . mb_substr($cmd, 0, 200) . "…\n→ " . ($e->errorInfo[2] ?? $e->getMessage()) . "\n");
            if (in_array($codigo, [1418, 1419, 1227], true)) {
                fwrite(STDERR, "\nO servidor não deixa este usuário criar este objeto (privilégio SUPER ou\n"
                    . "log_bin_trust_function_creators).\n");
            }
            exit(1);
        }
    }
    if ($mostrar) echo "$n comandos executados.\n";
    return $n;
}
