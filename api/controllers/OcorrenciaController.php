<?php
declare(strict_types=1);

// OcorrenciaController do diagrama de classes: listar (com filtro) e criar.
final class OcorrenciaController
{
    // GET /incidents?categoria=&de=&ate=  →  200 {ocorrencias, categorias}  (público, sem dados pessoais)
    public static function listar(): never
    {
        $filtro = FiltroOcorrencia::daQuery($_GET);
        $sessao = Sessao::atual();
        $quem = $sessao ? $sessao->usuario() : null;
        $lista = array_map(fn(Ocorrencia $o) => $o->paraArray($quem), Ocorrencia::listar($filtro));
        Response::json(['ocorrencias' => $lista, 'categorias' => Categoria::listar()]);
    }

    // POST /incidents  →  201 {ocorrencia} | 400 dados inválidos | 401 sem sessão
    //                  | 403 CSRF ou conta não validada | 409 repetida | 429 limite por hora
    public static function criar(): never
    {
        $sessao = Sessao::exigir(true);
        $usuario = $sessao->usuario();
        $c = Request::corpo();
        $ocorrencia = Ocorrencia::registrar([
            'categoria' => (string) ($c['tipo'] ?? ''),
            'endereco'  => Request::texto('local', 255),
            'descricao' => Request::texto('comentario', 600),
            'latitude'  => $c['lat'] ?? null,
            'longitude' => $c['lng'] ?? null,
        ], $sessao);
        // a auditoria da criação é gravada pelo próprio procedimento no banco
        Response::json(['ocorrencia' => $ocorrencia->paraArray($usuario)], 201);
    }
}
