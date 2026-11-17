<?php
// Backup dos DADOS do banco em backups/backup-AAAAMMDD-HHMMSS.sql
// (a estrutura vem do schema.sql). Rode pelo console e baixe o arquivo pelo explorador de arquivos.
// ATENÇÃO: o arquivo contém CPF e endereço dos usuários. Baixe, guarde em local seguro e apague do servidor.
//
//   php database/backup.php
declare(strict_types=1);
require __DIR__ . '/_sql.php';

$db = Database::conexao();
$pasta = __DIR__ . '/../backups';
if (!is_dir($pasta)) mkdir($pasta, 0700, true);
file_put_contents("$pasta/.htaccess", "Require all denied\n");

$tabelas = ['categoria', 'usuario', 'ocorrencia', 'registro_auditoria'];   // sessões e tentativas não precisam
$saida = "-- Backup gerado em " . date('Y-m-d H:i:s') . "\n"
       . "-- Restaurar:  php database/restaurar.php --arquivo=backups/NOME.sql\n"
       . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n";

foreach ($tabelas as $t) {
    $linhas = $db->query("SELECT * FROM `$t` ORDER BY id")->fetchAll();
    $saida .= "\n-- $t (" . count($linhas) . " linhas)\nDELETE FROM `$t`;\n";
    foreach (array_chunk($linhas, 200) as $bloco) {
        $cols = '`' . implode('`, `', array_keys($bloco[0])) . '`';
        $valores = array_map(function (array $l) use ($db) {
            return '(' . implode(', ', array_map(fn($v) => $v === null ? 'NULL' : $db->quote((string) $v), $l)) . ')';
        }, $bloco);
        $saida .= "INSERT INTO `$t` ($cols) VALUES\n" . implode(",\n", $valores) . ";\n";
    }
}
$saida .= "\nSET FOREIGN_KEY_CHECKS = 1;\n";

$arquivo = "$pasta/backup-" . date('Ymd-His') . ".sql";
file_put_contents($arquivo, $saida);
chmod($arquivo, 0600);
echo "Backup salvo em backups/" . basename($arquivo) . " (" . number_format(strlen($saida) / 1024, 1, ',', '.') . " KB)\n";
echo "Baixe pelo explorador de arquivos e depois apague do servidor (contém dados pessoais).\n";
