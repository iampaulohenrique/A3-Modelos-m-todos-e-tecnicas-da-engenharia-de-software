<?php
// Importa o banco pela linha de comando, usando a conexão do .env (DB_URL ou DB_*).
// Serve para a Discloud (console interativo), hospedagens sem phpMyAdmin e o XAMPP.
//
//   php database/importar.php                       → instalação nova (database/schema.sql)
//   php database/importar.php --arquivo=database/migracao_v2_seguranca.sql
declare(strict_types=1);
require __DIR__ . '/_sql.php';

$args = getopt('', ['arquivo:']);
$arquivo = $args['arquivo'] ?? __DIR__ . '/schema.sql';
$real = realpath($arquivo);
if (!$real || !str_starts_with($real, realpath(__DIR__) . DIRECTORY_SEPARATOR) || !str_ends_with($real, '.sql')) {
    fwrite(STDERR, "Arquivo inválido: use um .sql da pasta database/.\n");
    exit(1);
}

$db = Database::conexao();
$banco = $db->query('SELECT DATABASE()')->fetchColumn();
echo "Importando " . basename($real) . " no banco \"$banco\"…\n";
sql_executar($db, file_get_contents($real));

// camada extra: gatilhos (só onde o servidor permite)
echo "Instalando gatilhos (camada extra)… ";
$ok = sql_executar($db, file_get_contents(__DIR__ . '/gatilhos_extra.sql'), false, true);
echo $ok >= 0
    ? "instalados.\n"
    : "o servidor não permite gatilhos para este usuário. Sem problema: o procedimento continua validando tudo o que o sistema grava.\n";

$tabelas = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$gatilhos = $db->query('SHOW TRIGGERS')->fetchAll(PDO::FETCH_COLUMN);
echo "Tabelas: " . implode(', ', $tabelas) . "\n";
$procs = $db->query("SHOW PROCEDURE STATUS WHERE Db = DATABASE()")->fetchAll(PDO::FETCH_COLUMN, 1);
echo "Procedimentos: " . implode(', ', $procs) . "\n";
echo "Gatilhos: " . count($gatilhos) . ($gatilhos ? '' : ' (camada extra não instalada)') . "\n";
echo "Pronto. Agora crie o administrador:  php database/criar_admin.php\n";
