<?php
// Cria (ou promove) um ADMINISTRADOR pela linha de comando. Nunca pelo navegador.
// Uso interativo:  C:\xampp\php\php.exe database\criar_admin.php
// Sem perguntas (ex.: console da Discloud):
//   php database/criar_admin.php --nome="Seu Nome" --email=voce@exemplo.com --senha="SenhaForte#1"
//   (depois apague o histórico do console, se ele guardar o comando)
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../src/bootstrap.php';
restore_exception_handler();
header_remove();

function perguntar(string $rotulo): string
{
    echo $rotulo;
    return trim((string) fgets(STDIN));
}

$args = getopt('', ['nome:', 'email:', 'senha:']);
echo "== Criar administrador ==\n";
$nome  = $args['nome']  ?? perguntar('Nome completo: ');
$email = mb_strtolower(trim($args['email'] ?? perguntar('E-mail: ')));
$senha = $args['senha'] ?? perguntar('Senha (mínimo 8): ');

if (mb_strlen($nome) < 3 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Nome ou e-mail inválido.\n");
    exit(1);
}
if ($erro = Usuario::validarSenha($senha, $email, $nome)) {
    fwrite(STDERR, "Senha recusada: $erro\n");
    exit(1);
}

$db = Database::conexao();
$existe = Usuario::buscarPorEmail($email);
if ($existe) {
    $db->prepare("UPDATE usuario SET perfil = 'ADMINISTRADOR', senha_hash = ? WHERE id = ?")
       ->execute([password_hash($senha, PASSWORD_DEFAULT), $existe->id]);
    Sessao::encerrarTodas($existe->id);
    echo "Conta existente promovida a administrador e senha trocada.\n";
} else {
    $db->prepare("INSERT INTO usuario (nome, email, senha_hash, perfil) VALUES (?, ?, ?, 'ADMINISTRADOR')")
       ->execute([$nome, $email, password_hash($senha, PASSWORD_DEFAULT)]);
    echo "Administrador criado.\n";
}
echo "Para criar alertas, complete CPF e endereço em Minha conta depois de entrar.\n";
