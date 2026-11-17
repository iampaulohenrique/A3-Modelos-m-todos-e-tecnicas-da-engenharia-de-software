<?php
declare(strict_types=1);

// Controle de tentativas de login (força bruta e "credential stuffing").
// Conta falhas por e-mail e por IP numa janela de 15 minutos, gravadas no banco.
final class LimiteLogin
{
    private const JANELA_MIN = 15;

    private static function emailHash(string $email): string
    {
        return hash('sha256', mb_strtolower(trim($email)));
    }

    // Lança 429 se o e-mail ou o IP passou do limite de falhas.
    public static function verificar(string $email): void
    {
        $db = Database::conexao();
        $j = self::JANELA_MIN;

        // falhas do e-mail desde o último login bem-sucedido, dentro da janela
        $st = $db->prepare("SELECT COUNT(*) FROM tentativa_login
                            WHERE email_hash = ? AND sucesso = 0 AND data_hora > NOW() - INTERVAL $j MINUTE
                              AND data_hora > IFNULL((SELECT MAX(data_hora) FROM tentativa_login WHERE email_hash = ? AND sucesso = 1), '1970-01-01')");
        $h = self::emailHash($email);
        $st->execute([$h, $h]);
        $porEmail = (int) $st->fetchColumn();

        $st = $db->prepare("SELECT COUNT(*) FROM tentativa_login WHERE ip = ? AND sucesso = 0 AND data_hora > NOW() - INTERVAL $j MINUTE");
        $st->execute([Request::ip()]);
        $porIp = (int) $st->fetchColumn();

        if ($porEmail >= (int) config('login_max_por_email', 5) || $porIp >= (int) config('login_max_por_ip', 20)) {
            header('Retry-After: ' . ($j * 60));
            RegistroAuditoria::registrar('LOGIN_BLOQUEADO', null, null, 'WARN', 'ip', null, Request::ip());
            throw new HttpException("Muitas tentativas de login. Espere $j minutos e tente de novo.", 429, [], 'LOGIN_BLOQUEADO');
        }
    }

    public static function registrar(string $email, bool $sucesso): void
    {
        $db = Database::conexao();
        $db->prepare('INSERT INTO tentativa_login (email_hash, ip, sucesso) VALUES (?, ?, ?)')
           ->execute([self::emailHash($email), Request::ip(), $sucesso ? 1 : 0]);
        // limpeza: guarda só 1 dia de histórico
        if (random_int(1, 50) === 1) {
            $db->exec('DELETE FROM tentativa_login WHERE data_hora < NOW() - INTERVAL 1 DAY');
        }
    }

    // No máximo 5 contas novas por IP a cada hora (dificulta criar contas falsas em massa)
    public static function verificarCadastro(): void
    {
        $db = Database::conexao();
        $marca = hash('sha256', '#cadastro');
        $st = $db->prepare('SELECT COUNT(*) FROM tentativa_login WHERE email_hash = ? AND ip = ? AND data_hora > NOW() - INTERVAL 1 HOUR');
        $st->execute([$marca, Request::ip()]);
        if ((int) $st->fetchColumn() >= 5) {
            header('Retry-After: 3600');
            throw new HttpException('Muitos cadastros feitos desta rede. Tente de novo mais tarde.', 429, [], 'CADASTRO_BLOQUEADO');
        }
        $db->prepare('INSERT INTO tentativa_login (email_hash, ip, sucesso) VALUES (?, ?, 1)')->execute([$marca, Request::ip()]);
    }
}
