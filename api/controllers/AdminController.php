<?php
declare(strict_types=1);

// AdminController do diagrama de classes. Toda ação confere o perfil ADMINISTRADOR.
final class AdminController
{
    private static function exigirAdmin(bool $csrf = false): Usuario
    {
        $sessao = Sessao::exigir($csrf);
        $usuario = $sessao->usuario();
        if (!$usuario->ehAdministrador()) {
            RegistroAuditoria::registrar('ACESSO_NEGADO', $usuario->id, null, 'WARN', 'rota', null, $_SERVER['SCRIPT_NAME'] ?? '');
            throw new HttpException('Acesso negado: esta área é só para administradores.', 403);
        }
        return $usuario;
    }

    // GET /admin/users  →  200 {usuarios, totais}
    public static function listarUsuarios(): never
    {
        Request::exigirMetodo('GET');
        self::exigirAdmin();
        $db = Database::conexao();
        $totais = [
            'usuarios'    => (int) $db->query('SELECT COUNT(*) FROM usuario')->fetchColumn(),
            'ocorrencias' => (int) $db->query('SELECT COUNT(*) FROM ocorrencia')->fetchColumn(),
            // "eventos críticos" = quedas de talude, o tipo de maior risco
            'criticas'    => (int) $db->query("SELECT COUNT(*) FROM ocorrencia o JOIN categoria c ON c.id = o.categoria_id WHERE c.slug = 'talude'")->fetchColumn(),
        ];
        Response::json(['usuarios' => Usuario::listarComContagem(), 'totais' => $totais]);
    }

    // PUT /admin/incidents/{id}  →  200 {ocorrencia} | 404
    public static function editarOcorrencia(int $id): never
    {
        $admin = self::exigirAdmin(true);
        $o = Ocorrencia::buscarPorId($id);
        if (!$o) throw new HttpException('Ocorrência não encontrada.', 404);

        $c = Request::corpo();
        $mudancas = $o->atualizar(
            isset($c['tipo']) ? (string) $c['tipo'] : null,
            array_key_exists('comentario', $c) ? Request::texto('comentario', 600) : null
        );
        foreach ($mudancas as [$campo, $antes, $depois]) {
            RegistroAuditoria::registrar('EDITAR_OCORRENCIA', $admin->id, $o->id, 'INFO', $campo, $antes, $depois);
        }
        Response::json(['ocorrencia' => $o->paraArray($admin)]);
    }

    // DELETE /admin/incidents/{id}  →  204 | 404
    public static function excluirOcorrencia(int $id): never
    {
        $admin = self::exigirAdmin(true);
        $o = Ocorrencia::buscarPorId($id);
        if (!$o) throw new HttpException('Ocorrência não encontrada.', 404);

        $resumo = json_encode($o->paraArray(), JSON_UNESCAPED_UNICODE);
        RegistroAuditoria::registrar('EXCLUIR_OCORRENCIA', $admin->id, $o->id, 'WARN', 'ocorrencia', $resumo, null);
        $o->excluir();
        Response::semConteudo();
    }

    // GET /admin/logs  →  200 {logs}
    public static function logs(): never
    {
        Request::exigirMetodo('GET');
        self::exigirAdmin();
        $limite = max(1, min(500, (int) ($_GET['limite'] ?? 100)));
        Response::json(['logs' => RegistroAuditoria::listar($limite)]);
    }

    // POST /admin/purge {dias}  →  200 {removidas}   ("Limpar alertas antigos")
    public static function limparAntigas(): never
    {
        Request::exigirMetodo('POST');
        $admin = self::exigirAdmin(true);
        $dias = (int) (Request::corpo()['dias'] ?? 90);
        if ($dias < 7) throw new HttpException('Use pelo menos 7 dias.', 400);

        $db = Database::conexao();
        $st = $db->prepare('SELECT id FROM ocorrencia WHERE data_hora < (NOW() - INTERVAL ? DAY)');
        $st->execute([$dias]);
        $ids = $st->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            RegistroAuditoria::registrar('EXCLUIR_OCORRENCIA', $admin->id, (int) $id, 'WARN', 'limpeza', "mais de $dias dias", null);
        }
        if ($ids) {
            $db->prepare('DELETE FROM ocorrencia WHERE data_hora < (NOW() - INTERVAL ? DAY)')->execute([$dias]);
        }
        Response::json(['removidas' => count($ids)]);
    }
}
