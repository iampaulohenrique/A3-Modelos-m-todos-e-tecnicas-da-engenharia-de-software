<?php
declare(strict_types=1);

// Ocorrencia do diagrama de classes.
final class Ocorrencia
{
    public function __construct(
        public int $id,
        public int $usuarioId,
        public string $categoria,   // slug: alagamento | erosao | talude
        public string $descricao,
        public string $endereco,
        public float $latitude,
        public float $longitude,
        public string $dataHora
    ) {}

    private const SELECT = 'SELECT o.*, c.slug AS categoria FROM ocorrencia o JOIN categoria c ON c.id = o.categoria_id';

    private static function deLinha(array $l): self
    {
        return new self(
            (int) $l['id'], (int) $l['usuario_id'], $l['categoria'], $l['descricao'], $l['endereco'],
            (float) $l['latitude'], (float) $l['longitude'], $l['data_hora']
        );
    }

    // Valida os dados recebidos; devolve os erros por campo (vazio = tudo certo).
    public static function validar(array $d): array
    {
        $erros = [];
        if (!Categoria::idPorSlug((string) ($d['categoria'] ?? ''))) $erros['tipo'] = 'Escolha o tipo de ocorrência.';
        if (trim((string) ($d['endereco'] ?? '')) === '') $erros['local'] = 'Informe a rodovia ou rua.';
        if (mb_strlen((string) ($d['descricao'] ?? '')) > 500) $erros['comentario'] = 'Use no máximo 500 caracteres.';
        if (!self::validarLocalizacao($d['latitude'] ?? null, $d['longitude'] ?? null)) $erros['mapa'] = 'Marque o ponto no mapa.';
        return $erros;
    }

    public static function validarLocalizacao($lat, $lng): bool
    {
        return is_numeric($lat) && is_numeric($lng)
            && (float) $lat >= -90 && (float) $lat <= 90
            && (float) $lng >= -180 && (float) $lng <= 180;
    }

    // Grava uma nova ocorrência. O PHP só pré-valida; quem decide é o BANCO:
    // o procedimento sp_registrar_ocorrencia confere a sessão pelo hash do token, conta validada
    // (CPF + endereço), tipo, ponto no Brasil, limite por hora e repetição.
    // Onde o servidor permite gatilhos (gatilhos_extra.sql), até INSERT manual passa pelas mesmas regras.
    public static function registrar(array $d, Sessao $sessao): self
    {
        $erros = self::validar($d);
        if ($erros) throw new HttpException('Confira os dados da ocorrência.', 400, $erros);

        $db = Database::conexao();
        $st = $db->prepare('CALL sp_registrar_ocorrencia(?, ?, ?, ?, ?, ?, @nova_ocorrencia)');
        $st->execute([
            $sessao->tokenHash(),
            (string) $d['categoria'],
            trim((string) ($d['descricao'] ?? '')),
            trim((string) $d['endereco']),
            round((float) $d['latitude'], 7),
            round((float) $d['longitude'], 7),
        ]);
        $st->closeCursor();
        $id = (int) $db->query('SELECT @nova_ocorrencia')->fetchColumn();
        return self::buscarPorId($id);
    }

    public static function buscarPorId(int $id): ?self
    {
        $st = Database::conexao()->prepare(self::SELECT . ' WHERE o.id = ?');
        $st->execute([$id]);
        $l = $st->fetch();
        return $l ? self::deLinha($l) : null;
    }

    /** @return self[] */
    public static function listar(FiltroOcorrencia $filtro, int $limite = 500): array
    {
        [$where, $params] = $filtro->aplicar();
        $st = Database::conexao()->prepare(self::SELECT . " $where ORDER BY o.data_hora DESC, o.id DESC LIMIT " . (int) $limite);
        $st->execute($params);
        return array_map([self::class, 'deLinha'], $st->fetchAll());
    }

    // Altera categoria e/ou descrição; devolve a lista de mudanças [campo, antes, depois].
    public function atualizar(?string $categoria, ?string $descricao): array
    {
        $mudancas = [];
        if ($categoria !== null && $categoria !== $this->categoria) {
            if (!Categoria::idPorSlug($categoria)) throw new HttpException('Categoria desconhecida.', 400, ['tipo' => 'Categoria desconhecida.']);
            $mudancas[] = ['categoria', $this->categoria, $categoria];
            $this->categoria = $categoria;
        }
        if ($descricao !== null && $descricao !== $this->descricao) {
            if (mb_strlen($descricao) > 500) throw new HttpException('Use no máximo 500 caracteres.', 400, ['comentario' => 'Use no máximo 500 caracteres.']);
            $mudancas[] = ['descricao', $this->descricao, $descricao];
            $this->descricao = $descricao;
        }
        if ($mudancas) {
            Database::conexao()->prepare('UPDATE ocorrencia SET categoria_id = ?, descricao = ? WHERE id = ?')
                ->execute([Categoria::idPorSlug($this->categoria), $this->descricao, $this->id]);
        }
        return $mudancas;
    }

    public function excluir(): void
    {
        Database::conexao()->prepare('DELETE FROM ocorrencia WHERE id = ?')->execute([$this->id]);
    }

    // Dados públicos (sem nome ou e-mail de quem registrou).
    public function paraArray(?Usuario $quemPede = null): array
    {
        $t = strtotime($this->dataHora);
        return [
            'id' => $this->id,
            'tipo' => $this->categoria,
            'local' => $this->endereco,
            'comentario' => $this->descricao,
            'lat' => $this->latitude,
            'lng' => $this->longitude,
            'dataHora' => date('c', $t),
            'minha' => $quemPede !== null && $quemPede->id === $this->usuarioId,
        ];
    }
}
