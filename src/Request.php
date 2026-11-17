<?php
declare(strict_types=1);

// Utilidades para ler a requisição.
final class Request
{
    public static function metodo(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    // Garante o método HTTP; responde 405 se for outro.
    public static function exigirMetodo(string ...$permitidos): string
    {
        $m = self::metodo();
        if (!in_array($m, $permitidos, true)) {
            header('Allow: ' . implode(', ', $permitidos));
            throw new HttpException('Método não permitido.', 405);
        }
        return $m;
    }

    // Corpo JSON como array (aceita também formulário comum).
    public static function corpo(): array
    {
        static $corpo = null;
        if ($corpo !== null) {
            return $corpo;
        }
        $tipo = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($tipo, 'application/json') !== false) {
            $bruto = file_get_contents('php://input') ?: '';
            $dados = json_decode($bruto, true);
            if ($bruto !== '' && !is_array($dados)) {
                throw new HttpException('JSON inválido no corpo da requisição.', 400);
            }
            $corpo = $dados ?? [];
        } else {
            $corpo = $_POST;
        }
        return $corpo;
    }

    public static function texto(string $campo, int $max = 255): string
    {
        $v = self::corpo()[$campo] ?? '';
        $v = is_scalar($v) ? trim((string) $v) : '';
        return mb_substr($v, 0, $max);
    }

    public static function cabecalho(string $nome): string
    {
        $chave = 'HTTP_' . strtoupper(str_replace('-', '_', $nome));
        return (string) ($_SERVER[$chave] ?? '');
    }

    // Atrás de um proxy confiável (Discloud, Cloudflare, load balancer) o PHP recebe HTTP puro
    // e o IP do proxy. Com TRUST_PROXY=true no .env, os cabeçalhos X-Forwarded-* passam a valer.
    // NUNCA ligue isso sem proxy na frente: o cliente poderia forjar IP e HTTPS.
    public static function confiaProxy(): bool
    {
        return (bool) config('trust_proxy', false);
    }

    public static function https(): bool
    {
        if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }
        return self::confiaProxy() && strtolower(self::cabecalho('X-Forwarded-Proto')) === 'https';
    }

    public static function ip(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        if (self::confiaProxy()) {
            // o proxy acrescenta o IP real do cliente no FIM da lista; o começo pode ser forjado
            $lista = array_map('trim', explode(',', self::cabecalho('X-Forwarded-For')));
            $ultimo = end($lista);
            if ($ultimo && filter_var($ultimo, FILTER_VALIDATE_IP)) $ip = $ultimo;
        }
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }

    public static function host(): string
    {
        $h = self::confiaProxy() && self::cabecalho('X-Forwarded-Host') !== ''
            ? self::cabecalho('X-Forwarded-Host')
            : (string) ($_SERVER['HTTP_HOST'] ?? '');
        return strtolower(trim(explode(',', $h)[0]));
    }

    // Bloqueia CSRF por origem: POST/PUT/DELETE só do mesmo host (cabeçalho Origin,
    // ou Sec-Fetch-Site nos navegadores atuais). Complementa o token CSRF.
    public static function exigirMesmaOrigem(): void
    {
        if (in_array(self::metodo(), ['GET', 'HEAD', 'OPTIONS'], true)) return;
        $host = self::host();
        $origin = self::cabecalho('Origin');
        if ($origin !== '' && $origin !== 'null') {
            $h = strtolower((string) parse_url($origin, PHP_URL_HOST));
            $p = parse_url($origin, PHP_URL_PORT);
            $hostSemPorta = preg_replace('/:\d+$/', '', $host);
            // atrás de proxy a porta interna (8080) some do Host público: compara só o nome
            $ok = ($p ? "$h:$p" : $h) === $host || (self::confiaProxy() && $h === $hostSemPorta);
            if (!$ok) {
                throw new HttpException('Requisição de outra origem recusada.', 403, [], 'ORIGEM_INVALIDA');
            }
            return;
        }
        $site = self::cabecalho('Sec-Fetch-Site');
        if ($site !== '' && !in_array($site, ['same-origin', 'none'], true)) {
            throw new HttpException('Requisição de outra origem recusada.', 403, [], 'ORIGEM_INVALIDA');
        }
    }

    public static function idDaQuery(): int
    {
        $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false || $id === null) {
            throw new HttpException('Informe um id válido.', 400);
        }
        return $id;
    }
}
