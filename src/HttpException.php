<?php
declare(strict_types=1);

// Erro esperado da API: status HTTP, erros por campo e um código estável
// (ex.: PERFIL_INCOMPLETO) que o front pode usar para decidir o que mostrar.
class HttpException extends RuntimeException
{
    public int $status;
    public array $campos;
    public ?string $codigo;

    public function __construct(string $mensagem, int $status = 400, array $campos = [], ?string $codigo = null)
    {
        parent::__construct($mensagem);
        $this->status = $status;
        $this->campos = $campos;
        $this->codigo = $codigo;
    }
}
