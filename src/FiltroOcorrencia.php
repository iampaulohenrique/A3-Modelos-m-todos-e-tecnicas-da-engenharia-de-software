<?php
declare(strict_types=1);

// FiltroOcorrencia do diagrama de classes: categoria + período.
// Não é gravado no banco; só monta a consulta.
final class FiltroOcorrencia
{
    public ?string $categoria = null;
    public ?string $dataInicio = null; // Y-m-d
    public ?string $dataFim = null;    // Y-m-d

    // Lê ?categoria=talude&de=2026-11-01&ate=2026-11-30
    public static function daQuery(array $q): self
    {
        $f = new self();
        $cat = isset($q['categoria']) ? (string) $q['categoria'] : '';
        if ($cat !== '' && $cat !== 'todas') {
            if (!Categoria::idPorSlug($cat)) throw new HttpException('Categoria desconhecida.', 400);
            $f->categoria = $cat;
        }
        $f->dataInicio = self::data($q['de'] ?? null, 'de');
        $f->dataFim = self::data($q['ate'] ?? null, 'ate');
        if ($f->dataInicio && $f->dataFim && $f->dataInicio > $f->dataFim) {
            throw new HttpException('A data inicial é depois da final.', 400);
        }
        return $f;
    }

    private static function data($v, string $campo): ?string
    {
        if ($v === null || $v === '') return null;
        $d = DateTime::createFromFormat('Y-m-d', (string) $v);
        if (!$d || $d->format('Y-m-d') !== $v) {
            throw new HttpException("Data inválida em \"$campo\" (use AAAA-MM-DD).", 400);
        }
        return $v;
    }

    // Devolve [trecho WHERE, parâmetros]
    public function aplicar(): array
    {
        $where = [];
        $params = [];
        if ($this->categoria) {
            $where[] = 'c.slug = ?';
            $params[] = $this->categoria;
        }
        if ($this->dataInicio) {
            $where[] = 'o.data_hora >= ?';
            $params[] = $this->dataInicio . ' 00:00:00';
        }
        if ($this->dataFim) {
            $where[] = 'o.data_hora <= ?';
            $params[] = $this->dataFim . ' 23:59:59';
        }
        return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $params];
    }
}
