<?php
declare(strict_types=1);

// Traduz as recusas do banco (SIGNAL dos gatilhos e procedimentos, CHECK, UNIQUE)
// em respostas HTTP com mensagem clara. O banco é a última palavra na validação.
final class DbErro
{
    private const SINAIS = [
        'SESSAO_INVALIDA'      => [401, 'Sua sessão terminou. Entre de novo para registrar o alerta.', []],
        'PERFIL_INCOMPLETO'    => [403, 'Complete seu cadastro (CPF e endereço) para registrar alertas.', []],
        'CATEGORIA_INVALIDA'   => [400, 'Escolha o tipo de ocorrência.', ['tipo' => 'Escolha o tipo de ocorrência.']],
        'ENDERECO_INVALIDO'    => [400, 'Informe a rodovia ou rua.', ['local' => 'Informe a rodovia ou rua.']],
        'FORA_DO_BRASIL'       => [400, 'O ponto marcado fica fora do Brasil.', ['mapa' => 'Marque um ponto dentro do Brasil.']],
        'LIMITE_OCORRENCIAS'   => [429, 'Você chegou ao limite de 10 alertas por hora. Tente mais tarde.', []],
        'OCORRENCIA_DUPLICADA' => [409, 'Você já registrou esse tipo de ocorrência nesse ponto há pouco.', []],
        'CPF_INVALIDO'         => [422, 'CPF inválido. Confira os números.', ['cpf' => 'CPF inválido.']],
        'CPF_IMUTAVEL'         => [422, 'O CPF não pode ser alterado depois de cadastrado.', ['cpf' => 'O CPF não pode ser alterado.']],
        'CAMPO_IMUTAVEL'       => [422, 'Local, autor e data de uma ocorrência não podem ser alterados.', []],
    ];

    public static function mapear(PDOException $e): ?HttpException
    {
        $info = $e->errorInfo ?? [];
        $sqlstate = $info[0] ?? $e->getCode();
        $driver = (int) ($info[1] ?? 0);
        $msg = (string) ($info[2] ?? $e->getMessage());

        // SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CODIGO'
        if ($sqlstate === '45000') {
            foreach (self::SINAIS as $codigo => [$status, $texto, $campos]) {
                if (str_contains($msg, $codigo)) {
                    return new HttpException($texto, $status, $campos, $codigo);
                }
            }
        }
        // 1062 = valor duplicado em coluna UNIQUE
        if ($driver === 1062) {
            if (str_contains($msg, 'cpf')) {
                return new HttpException('Este CPF já está cadastrado em outra conta.', 409, ['cpf' => 'CPF já cadastrado.'], 'CPF_DUPLICADO');
            }
            if (str_contains($msg, 'email')) {
                return new HttpException('Este e-mail já está cadastrado.', 409, ['email' => 'E-mail já cadastrado.'], 'EMAIL_DUPLICADO');
            }
        }
        // 4025 (MariaDB) / 3819 (MySQL) = CHECK constraint violada: o nome da regra diz o campo
        if ($driver === 4025 || $driver === 3819) {
            $porRegra = [
                'ck_usuario_cpf'   => ['cpf', 'CPF inválido. Confira os números.', 'CPF_INVALIDO'],
                'ck_usuario_cep'   => ['cep', 'CEP com 8 números.', 'CEP_INVALIDO'],
                'ck_usuario_uf'    => ['uf', 'Escolha o estado.', 'UF_INVALIDA'],
                'ck_usuario_nome'  => ['nome', 'Informe seu nome completo.', 'NOME_INVALIDO'],
                'ck_usuario_email' => ['email', 'Confira o e-mail.', 'EMAIL_INVALIDO'],
            ];
            foreach ($porRegra as $regra => [$campo, $texto, $codigo]) {
                if (str_contains($msg, $regra)) {
                    return new HttpException($texto, 422, [$campo => $texto], $codigo);
                }
            }
            return new HttpException('Algum dado não passou na validação do banco. Confira os campos.', 422, [], 'CHECK_VIOLADO');
        }
        return null;
    }
}
