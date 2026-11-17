<?php
declare(strict_types=1);

// Usuario do diagrama de classes.
// Conta "validada" = nome + CPF válido + endereço completo. Quem decide é o banco
// (CHECK do CPF + view vw_usuario_validado); o PHP valida antes só para dar mensagens melhores.
final class Usuario
{
    public const COMUM = 'USUARIO_COMUM';
    public const ADMIN = 'ADMINISTRADOR';

    // hash de uma senha qualquer, usado quando o e-mail não existe (tempo de resposta igual)
    private const HASH_FALSO = '$2y$10$YV4N/qIAFIFo5xklFTGBKuJXT7LxBaIYGfwl/Hn8COnwLKo6Ezqly';

    private const SENHAS_COMUNS = ['12345678', '123456789', '1234567890', 'password', 'senha123', 'senha1234',
        'qwerty123', '11111111', '00000000', 'abcd1234', 'admin123', 'iloveyou', 'mudar123', 'brasil123'];

    public const UFS = ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'];

    public function __construct(
        public int $id,
        public string $nome,
        public string $email,
        private string $senhaHash,
        public string $perfil,
        public string $criadoEm,
        public ?string $cpf = null,
        public array $endereco = []
    ) {}

    private static function deLinha(array $l): self
    {
        return new self((int) $l['id'], $l['nome'], $l['email'], $l['senha_hash'], $l['perfil'], $l['criado_em'],
            $l['cpf'] ?? null, [
                'cep' => $l['cep'] ?? null, 'logradouro' => $l['logradouro'] ?? null, 'numero' => $l['numero'] ?? null,
                'complemento' => $l['complemento'] ?? null, 'bairro' => $l['bairro'] ?? null,
                'cidade' => $l['cidade'] ?? null, 'uf' => $l['uf'] ?? null,
            ]);
    }

    // ---------- validações ----------
    public static function apenasDigitos(?string $v): string
    {
        return preg_replace('/\D+/', '', (string) $v);
    }

    public static function cpfValido(string $cpf): bool
    {
        if (!preg_match('/^\d{11}$/', $cpf) || preg_match('/^(\d)\1{10}$/', $cpf)) return false;
        for ($t = 9; $t < 11; $t++) {
            $s = 0;
            for ($i = 0; $i < $t; $i++) $s += (int) $cpf[$i] * (($t + 1) - $i);
            $d = ((10 * $s) % 11) % 10;
            if ((int) $cpf[$t] !== $d) return false;
        }
        return true;
    }

    // Política de senha: 8 a 72 bytes (limite do bcrypt), não comum, sem o e-mail ou o nome
    public static function validarSenha(string $senha, string $email = '', string $nome = ''): ?string
    {
        if (mb_strlen($senha) < 8) return 'Use pelo menos 8 caracteres.';
        if (strlen($senha) > 72) return 'Use no máximo 72 caracteres.';
        $baixa = mb_strtolower($senha);
        if (in_array($baixa, self::SENHAS_COMUNS, true)) return 'Essa senha é muito comum. Escolha outra.';
        $local = mb_strtolower(strtok($email, '@') ?: '');
        if ($local !== '' && mb_strlen($local) >= 4 && str_contains($baixa, $local)) return 'A senha não pode conter o seu e-mail.';
        $primeiro = mb_strtolower(strtok(trim($nome), ' ') ?: '');
        if (mb_strlen($primeiro) >= 4 && str_contains($baixa, $primeiro)) return 'A senha não pode conter o seu nome.';
        return null;
    }

    // ---------- cadastro e autenticação ----------
    public static function cadastrar(string $nome, string $email, string $senha): self
    {
        $email = mb_strtolower(trim($email));
        $erros = [];
        if (mb_strlen(trim($nome)) < 3) $erros['name'] = 'Informe seu nome completo.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $erros['email'] = 'Confira o e-mail: falta o @ ou o domínio.';
        if ($e = self::validarSenha($senha, $email, $nome)) $erros['password'] = $e;
        if ($erros) throw new HttpException('Confira os campos destacados.', 422, $erros);

        $db = Database::conexao();
        // o UNIQUE do banco é quem garante e-mail único (DbErro traduz o 1062 em 409)
        $db->prepare('INSERT INTO usuario (nome, email, senha_hash, perfil) VALUES (?, ?, ?, ?)')
           ->execute([trim($nome), $email, password_hash($senha, PASSWORD_DEFAULT), self::COMUM]);
        return self::buscarPorId((int) $db->lastInsertId());
    }

    public static function buscarPorEmail(string $email): ?self
    {
        $st = Database::conexao()->prepare('SELECT * FROM usuario WHERE email = ?');
        $st->execute([mb_strtolower(trim($email))]);
        $l = $st->fetch();
        return $l ? self::deLinha($l) : null;
    }

    public static function buscarPorId(int $id): ?self
    {
        $st = Database::conexao()->prepare('SELECT * FROM usuario WHERE id = ?');
        $st->execute([$id]);
        $l = $st->fetch();
        return $l ? self::deLinha($l) : null;
    }

    // Confere e-mail e senha em tempo parecido exista ou não a conta (não revela quem tem cadastro).
    public static function autenticar(string $email, string $senha): ?self
    {
        $u = strlen($senha) <= 72 ? self::buscarPorEmail($email) : null;
        $ok = password_verify($senha, $u ? $u->senhaHash : self::HASH_FALSO);
        if (!$u || !$ok) return null;
        if (password_needs_rehash($u->senhaHash, PASSWORD_DEFAULT)) {
            Database::conexao()->prepare('UPDATE usuario SET senha_hash = ? WHERE id = ?')
                ->execute([password_hash($senha, PASSWORD_DEFAULT), $u->id]);
        }
        return $u;
    }

    public function ehAdministrador(): bool
    {
        return $this->perfil === self::ADMIN;
    }

    // Pergunta ao banco se a conta está validada (mesma regra que o gatilho usa)
    public function perfilCompleto(): bool
    {
        $st = Database::conexao()->prepare('SELECT perfil_completo FROM vw_usuario_validado WHERE id = ?');
        $st->execute([$this->id]);
        return (int) $st->fetchColumn() === 1;
    }

    // ---------- perfil (CPF e endereço) ----------
    public function atualizarPerfil(array $d, Sessao $sessao): void
    {
        $nome = trim((string) ($d['nome'] ?? $this->nome));
        $cpf = $this->cpf ?? self::apenasDigitos($d['cpf'] ?? '');
        $end = [
            'cep'         => self::apenasDigitos($d['cep'] ?? ''),
            'logradouro'  => trim((string) ($d['logradouro'] ?? '')),
            'numero'      => trim((string) ($d['numero'] ?? '')),
            'complemento' => trim((string) ($d['complemento'] ?? '')),
            'bairro'      => trim((string) ($d['bairro'] ?? '')),
            'cidade'      => trim((string) ($d['cidade'] ?? '')),
            'uf'          => strtoupper(trim((string) ($d['uf'] ?? ''))),
        ];

        $erros = [];
        if (mb_strlen($nome) < 3 || mb_strlen($nome) > 120) $erros['nome'] = 'Informe seu nome completo.';
        if ($this->cpf === null && !self::cpfValido($cpf)) $erros['cpf'] = 'CPF inválido. Confira os números.';
        if ($this->cpf !== null && isset($d['cpf']) && self::apenasDigitos($d['cpf']) !== '' && self::apenasDigitos($d['cpf']) !== $this->cpf) {
            $erros['cpf'] = 'O CPF não pode ser alterado.';
        }
        if (!preg_match('/^\d{8}$/', $end['cep'])) $erros['cep'] = 'CEP com 8 números.';
        if (mb_strlen($end['logradouro']) < 3 || mb_strlen($end['logradouro']) > 150) $erros['logradouro'] = 'Informe a rua.';
        if ($end['numero'] === '' || mb_strlen($end['numero']) > 10) $erros['numero'] = 'Informe o número (ou "s/n").';
        if (mb_strlen($end['complemento']) > 60) $erros['complemento'] = 'Use no máximo 60 caracteres.';
        if (mb_strlen($end['bairro']) < 2 || mb_strlen($end['bairro']) > 80) $erros['bairro'] = 'Informe o bairro.';
        if (mb_strlen($end['cidade']) < 2 || mb_strlen($end['cidade']) > 80) $erros['cidade'] = 'Informe a cidade.';
        if (!in_array($end['uf'], self::UFS, true)) $erros['uf'] = 'Escolha o estado.';
        if ($erros) throw new HttpException('Confira os campos destacados.', 422, $erros);

        // o banco valida de novo: o procedimento confere a sessão e impede trocar o CPF;
        // os CHECK da tabela conferem dígitos do CPF, CEP e UF
        $st = Database::conexao()->prepare('CALL sp_atualizar_perfil(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $st->execute([$sessao->tokenHash(), $nome, $cpf, $end['cep'], $end['logradouro'], $end['numero'],
                      $end['complemento'], $end['bairro'], $end['cidade'], $end['uf']]);
        $st->closeCursor();

        $this->nome = $nome;
        $this->cpf = $cpf;
        $this->endereco = $end;
    }

    public function cpfMascarado(): ?string
    {
        return $this->cpf ? '***.***.***-' . substr($this->cpf, 9, 2) : null;
    }

    // Dados seguros para o próprio usuário (nunca a senha; CPF só mascarado)
    public function paraArray(bool $comPerfil = false): array
    {
        $dados = [
            'id' => $this->id,
            'nome' => $this->nome,
            'email' => $this->email,
            'perfil' => $this->perfil,
            'perfilCompleto' => $this->perfilCompleto(),
        ];
        if ($comPerfil) {
            $dados['cpf'] = $this->cpfMascarado();
            $dados['endereco'] = $this->endereco;
        }
        return $dados;
    }

    // Lista para o painel admin, com a contagem de ocorrências (sem CPF e endereço)
    public static function listarComContagem(): array
    {
        $sql = 'SELECT u.id, u.nome, u.email, u.perfil, u.criado_em, MAX(v.perfil_completo) AS completo, COUNT(o.id) AS ocorrencias
                FROM usuario u
                JOIN vw_usuario_validado v ON v.id = u.id
                LEFT JOIN ocorrencia o ON o.usuario_id = u.id
                GROUP BY u.id ORDER BY u.id';
        return array_map(fn($l) => [
            'id' => (int) $l['id'],
            'nome' => $l['nome'],
            'email' => $l['email'],
            'perfil' => $l['perfil'],
            'perfilCompleto' => (int) $l['completo'] === 1,
            'criadoEm' => $l['criado_em'],
            'ocorrencias' => (int) $l['ocorrencias'],
        ], Database::conexao()->query($sql)->fetchAll());
    }
}
