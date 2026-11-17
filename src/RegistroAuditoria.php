<?php
declare(strict_types=1);

// RegistroAuditoria do diagrama de classes.
final class RegistroAuditoria
{
    public static function registrar(
        string $acao,
        ?int $usuarioId = null,
        ?int $ocorrenciaId = null,
        string $nivel = 'INFO',
        ?string $campo = null,
        ?string $anterior = null,
        ?string $novo = null
    ): void {
        Database::conexao()
            ->prepare('INSERT INTO registro_auditoria (usuario_id, ocorrencia_id, acao, nivel, campo_alterado, valor_anterior, valor_novo) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$usuarioId, $ocorrenciaId, $acao, $nivel, $campo, $anterior, $novo]);
    }

    public static function listar(int $limite = 100): array
    {
        $st = Database::conexao()->prepare(
            'SELECT a.id, a.acao, a.nivel, a.campo_alterado, a.valor_anterior, a.valor_novo, a.data_hora,
                    a.ocorrencia_id, u.nome AS usuario
             FROM registro_auditoria a LEFT JOIN usuario u ON u.id = a.usuario_id
             ORDER BY a.id DESC LIMIT ?'
        );
        $st->bindValue(1, $limite, PDO::PARAM_INT);
        $st->execute();
        return array_map(fn($l) => [
            'id' => (int) $l['id'],
            'acao' => $l['acao'],
            'nivel' => $l['nivel'],
            'usuario' => $l['usuario'],
            'ocorrenciaId' => $l['ocorrencia_id'] !== null ? (int) $l['ocorrencia_id'] : null,
            'campoAlterado' => $l['campo_alterado'],
            'valorAnterior' => $l['valor_anterior'],
            'valorNovo' => $l['valor_novo'],
            'dataHora' => $l['data_hora'],
        ], $st->fetchAll());
    }
}
