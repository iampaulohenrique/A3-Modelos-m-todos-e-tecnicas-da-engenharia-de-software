<?php
declare(strict_types=1);

// AuthController do diagrama de classes: cadastro, login, logout e "quem sou eu".
final class AuthController
{
    // POST /auth/register  →  201 {usuario, csrf} | 409 e-mail já cadastrado | 422 campos inválidos
    public static function register(): never
    {
        Request::exigirMetodo('POST');
        $senha = (string) (Request::corpo()['password'] ?? '');
        $confirma = (string) (Request::corpo()['confirm_password'] ?? $senha);
        if ($senha !== $confirma) {
            throw new HttpException('As senhas não são iguais.', 422, ['confirm_password' => 'As senhas não são iguais.']);
        }
        LimiteLogin::verificarCadastro();
        $usuario = Usuario::cadastrar(Request::texto('name', 120), Request::texto('email', 190), $senha);
        RegistroAuditoria::registrar('CADASTRO', $usuario->id);

        // Já entra na conta depois do cadastro
        $sessao = Sessao::criar($usuario);
        Response::json(['usuario' => $usuario->paraArray(), 'csrf' => $sessao->csrfToken], 201);
    }

    // POST /auth/login  →  200 {usuario, csrf} | 401 credenciais inválidas | 429 muitas tentativas
    public static function login(): never
    {
        Request::exigirMetodo('POST');
        $email = Request::texto('email', 190);
        $senha = (string) (Request::corpo()['password'] ?? '');
        if ($email === '' || $senha === '') {
            throw new HttpException('Informe e-mail e senha.', 422);
        }
        LimiteLogin::verificar($email);

        $usuario = Usuario::autenticar($email, $senha);
        LimiteLogin::registrar($email, $usuario !== null);
        if (!$usuario) {
            RegistroAuditoria::registrar('LOGIN_FALHOU', null, null, 'WARN', 'ip', null, Request::ip());
            // mesma mensagem para e-mail inexistente e senha errada (não revela quem tem conta)
            throw new HttpException('E-mail ou senha incorretos.', 401);
        }
        $sessao = Sessao::criar($usuario);
        RegistroAuditoria::registrar('LOGIN', $usuario->id, null, 'INFO', 'ip', null, Request::ip());
        Response::json(['usuario' => $usuario->paraArray(), 'csrf' => $sessao->csrfToken]);
    }

    // POST /auth/logout  →  200
    public static function logout(): never
    {
        Request::exigirMetodo('POST');
        $sessao = Sessao::atual();
        if ($sessao) {
            $sessao->exigirCsrf();
            RegistroAuditoria::registrar('LOGOUT', $sessao->usuarioId);
            $sessao->encerrar();
        }
        Response::json(['mensagem' => 'Sessão encerrada.']);
    }

    // GET /auth/me  →  200 {usuario, csrf} (usuario = null se ninguém estiver logado)
    public static function me(): never
    {
        Request::exigirMetodo('GET');
        $sessao = Sessao::atual();
        if (!$sessao) {
            Response::json(['usuario' => null, 'csrf' => null]);
        }
        Response::json(['usuario' => $sessao->usuario()->paraArray(), 'csrf' => $sessao->csrfToken]);
    }

    // GET /auth/profile  →  200 {usuario com CPF mascarado e endereço}
    // PUT /auth/profile  →  200 {usuario} | 409 CPF de outra conta | 422 campos inválidos
    public static function perfil(): never
    {
        $metodo = Request::exigirMetodo('GET', 'PUT');
        $sessao = Sessao::exigir($metodo === 'PUT');
        $usuario = $sessao->usuario();
        if ($metodo === 'PUT') {
            $usuario->atualizarPerfil(Request::corpo(), $sessao);
            RegistroAuditoria::registrar('ATUALIZAR_PERFIL', $usuario->id);
        }
        Response::json(['usuario' => $usuario->paraArray(true)]);
    }
}
