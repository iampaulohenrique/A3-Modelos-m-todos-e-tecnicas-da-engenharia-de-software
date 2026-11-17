<?php
declare(strict_types=1);

// Conexão PDO única por requisição (padrão Singleton).
final class Database
{
    private static ?PDO $conexao = null;

    private function __construct() {}

    public static function conexao(): PDO
    {
        if (self::$conexao === null) {
            $db = config('db');
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'] ?? 3306, $db['database']);
            try {
                self::$conexao = new PDO($dsn, $db['user'], $db['password'], [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
                self::$conexao->exec("SET time_zone = '" . (new DateTime())->format('P') . "'");
            } catch (PDOException $e) {
                error_log('Falha ao conectar no banco: ' . $e->getMessage());
                $msg = 'Não foi possível conectar ao banco de dados. Confira se o banco está ligado e a conexão no .env (rode php database/diagnostico.php).';
                if (config('debug')) {
                    $msg .= ' Detalhe: ' . $e->getMessage();
                }
                throw new HttpException($msg, 503);
            }
        }
        return self::$conexao;
    }
}
