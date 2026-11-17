<?php
declare(strict_types=1);

// Sessao do diagrama de classes.
// - token aleatório de 256 bits num cookie HttpOnly + SameSite=Strict (+ Secure e prefixo __Host- em HTTPS)
// - o banco guarda só o hash SHA-256 do token: vazar o banco não permite entrar em conta nenhuma
// - expira por inatividade (padrão 2 h) e por tempo máximo (padrão 8 h)
// - no máximo 5 sessões abertas por conta
final class Sessao
{
    private const MAX_POR_USUARIO = 5;

    private string $tokenHash;

    public function __construct(
        public int $id,
        public int $usuarioId,
        public string $csrfToken,
        public string $criadaEm,
        public string $expiraEm,
        string $tokenHash,
        public string $ultimoUso = ''
    ) {
        $this->tokenHash = $tokenHash;
    }

    public static function nomeCookie(): string
    {
        // __Host- exige Secure, Path=/ e nenhum Domain: o navegador impede subdomínios de sobrescrever
        return Request::https() ? '__Host-ocorrencias_sid' : 'ocorrencias_sid';
    }

    private static function expiracao(string $criadaEm): string
    {
        $maxima = strtotime($criadaEm) + (int) config('session_hours', 8) * 3600;
        $ociosa = time() + (int) config('session_idle_minutes', 120) * 60;
        return date('Y-m-d H:i:s', min($maxima, $ociosa));
    }

    // Abre uma sessão nova (sempre um token novo: evita fixação de sessão).
    public static function criar(Usuario $usuario): self
    {
        $token = bin2hex(random_bytes(32));
        $csrf = bin2hex(random_bytes(32));
        $agora = date('Y-m-d H:i:s');
        $expira = self::expiracao($agora);
        $hash = hash('sha256', $token);

        $db = Database::conexao();
        $db->exec('DELETE FROM sessao WHERE expira_em < NOW()');
        $db->prepare('INSERT INTO sessao (usuario_id, token_hash, csrf_token, ip, criada_em, ultimo_uso, expira_em) VALUES (?, ?, ?, ?, ?, ?, ?)')
           ->execute([$usuario->id, $hash, $csrf, Request::ip(), $agora, $agora, $expira]);
        $id = (int) $db->lastInsertId();

        // mantém só as sessões mais recentes
        $db->prepare('DELETE FROM sessao WHERE usuario_id = ? AND id NOT IN (
                        SELECT id FROM (SELECT id FROM sessao WHERE usuario_id = ? ORDER BY id DESC LIMIT ' . self::MAX_POR_USUARIO . ') t)')
           ->execute([$usuario->id, $usuario->id]);

        self::gravarCookie($token, strtotime($agora) + (int) config('session_hours', 8) * 3600);
        return new self($id, $usuario->id, $csrf, $agora, $expira, $hash, $agora);
    }

    private static function gravarCookie(string $valor, int $expira): void
    {
        setcookie(self::nomeCookie(), $valor, [
            'expires'  => $expira,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Strict',
            'secure'   => Request::https(),
        ]);
    }

    // Sessão da requisição atual (pelo cookie), ou null. Renova a expiração por inatividade.
    public static function atual(): ?self
    {
        static $cache = false;
        if ($cache !== false) return $cache;

        $token = $_COOKIE[self::nomeCookie()] ?? '';
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return $cache = null;
        }
        $hash = hash('sha256', $token);
        $st = Database::conexao()->prepare('SELECT * FROM sessao WHERE token_hash = ?');
        $st->execute([$hash]);
        $l = $st->fetch();
        if (!$l) return $cache = null;

        $s = new self((int) $l['id'], (int) $l['usuario_id'], $l['csrf_token'], $l['criada_em'], $l['expira_em'], $hash, $l['ultimo_uso']);
        if (!$s->estaValida()) {
            $s->encerrar();
            return $cache = null;
        }
        // renova no máximo 1 vez por minuto (evita escrever no banco a cada requisição)
        if (time() - strtotime($s->ultimoUso) >= 60) {
            $s->expiraEm = self::expiracao($s->criadaEm);
            Database::conexao()->prepare('UPDATE sessao SET ultimo_uso = NOW(), expira_em = ? WHERE id = ?')
                ->execute([$s->expiraEm, $s->id]);
        }
        return $cache = $s;
    }

    public function estaValida(): bool
    {
        return strtotime($this->expiraEm) > time();
    }

    public function encerrar(): void
    {
        Database::conexao()->prepare('DELETE FROM sessao WHERE id = ?')->execute([$this->id]);
        self::gravarCookie('', time() - 3600);
    }

    // Hash do token: é o que o banco usa para validar a sessão no procedimento de registro
    public function tokenHash(): string
    {
        return $this->tokenHash;
    }

    public function usuario(): Usuario
    {
        $u = Usuario::buscarPorId($this->usuarioId);
        if (!$u) throw new HttpException('Sessão inválida. Entre de novo.', 401, [], 'SESSAO_INVALIDA');
        return $u;
    }

    // Confere o token CSRF enviado no cabeçalho X-CSRF-Token (comparação em tempo constante).
    public function exigirCsrf(): void
    {
        $enviado = Request::cabecalho('X-CSRF-Token');
        if ($enviado === '' || !hash_equals($this->csrfToken, $enviado)) {
            throw new HttpException('Sua sessão expirou nesta aba. Recarregue a página e tente de novo.', 403, [], 'CSRF_INVALIDO');
        }
    }

    // Exige usuário logado; com $csrf = true também confere o token CSRF.
    public static function exigir(bool $csrf = false): self
    {
        $s = self::atual();
        if (!$s) throw new HttpException('Entre na sua conta para continuar.', 401, [], 'SESSAO_INVALIDA');
        if ($csrf) $s->exigirCsrf();
        return $s;
    }

    // Encerra todas as sessões da conta (ex.: depois de trocar a senha)
    public static function encerrarTodas(int $usuarioId): void
    {
        Database::conexao()->prepare('DELETE FROM sessao WHERE usuario_id = ?')->execute([$usuarioId]);
    }
}
