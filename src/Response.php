<?php
declare(strict_types=1);

// Respostas JSON padronizadas: sucesso devolve os dados; erro devolve {erro, codigo?, campos?}.
final class Response
{
    public static function json($dados, int $status = 200): never
    {
        http_response_code($status);
        echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
        exit;
    }

    public static function semConteudo(): never
    {
        http_response_code(204);
        exit;
    }

    public static function erro(string $mensagem, int $status = 400, array $campos = [], ?string $codigo = null): never
    {
        $corpo = ['erro' => $mensagem];
        if ($codigo) $corpo['codigo'] = $codigo;
        if ($campos) $corpo['campos'] = $campos;
        self::json($corpo, $status);
    }
}
