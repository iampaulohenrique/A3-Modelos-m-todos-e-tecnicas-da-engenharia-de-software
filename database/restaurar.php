<?php
// Restaura um backup feito com backup.php (substitui os dados atuais!).
// O gatilho de novas ocorrências (se existir) é desligado só durante a carga (senão ele trocaria
// as datas e aplicaria o limite por hora) e recriado em seguida.
//
//   php database/restaurar.php --arquivo=backups/backup-20261117-101500.sql
declare(strict_types=1);
require __DIR__ . '/_sql.php';

$args = getopt('', ['arquivo:', 'sim']);
$real = realpath($args['arquivo'] ?? '');
$pastaBackups = realpath(__DIR__ . '/../backups');
if (!$real || !$pastaBackups || !str_starts_with($real, $pastaBackups . DIRECTORY_SEPARATOR) || !str_ends_with($real, '.sql')) {
    fwrite(STDERR, "Informe um arquivo da pasta backups/: --arquivo=backups/NOME.sql\n");
    exit(1);
}
if (!isset($args['sim'])) {
    echo "Isso APAGA os dados atuais e carrega " . basename($real) . ". Digite RESTAURAR para continuar: ";
    if (trim((string) fgets(STDIN)) !== 'RESTAURAR') { echo "Cancelado.\n"; exit(0); }
}

$db = Database::conexao();
try { $db->exec('DROP TRIGGER IF EXISTS trg_ocorrencia_bi'); } catch (PDOException $e) { /* sem permissão = não havia gatilho */ }
try {
    sql_executar($db, file_get_contents($real));
} finally {
    // recria view, procedimentos e (se possível) gatilhos
    $schema = file_get_contents(__DIR__ . '/schema.sql');
    $inicio = strpos($schema, '-- Regras de validação no banco (VIEW + PROCEDURES)');
    sql_executar($db, substr($schema, $inicio), false);
    sql_executar($db, file_get_contents(__DIR__ . '/gatilhos_extra.sql'), false, true);
    $db->exec('DELETE FROM sessao');
    echo "Regras do banco recriadas. Todos precisam entrar de novo.\n";
}
