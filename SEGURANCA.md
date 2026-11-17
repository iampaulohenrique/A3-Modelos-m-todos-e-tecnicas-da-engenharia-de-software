# Segurança — Sistema de Monitoramento de Ocorrências Ambientais

Contexto: PHP 8.1+ puro (sem framework), MariaDB/MySQL com PDO, API REST + páginas estáticas, painel administrativo.
Data da revisão: 17/11/2026.

---

## 1. Vulnerabilidades encontradas e status

| # | Criticidade | Vulnerabilidade | Onde estava | Status |
|---|---|---|---|---|
| 1 | **Alta** | Administrador padrão com senha pública (`admin@exemplo.com` / `Admin@123`) no `schema.sql` e no README | `database/schema.sql` | Corrigido: sem admin padrão; criação só por terminal (`database/criar_admin.php`) |
| 2 | **Alta** | Login sem limite de tentativas (força bruta e *credential stuffing*) | `AuthController::login` | Corrigido: 5 falhas por e-mail ou 20 por IP em 15 min → HTTP 429 |
| 3 | **Alta** | Alertas falsos: qualquer conta recém-criada podia registrar ocorrências, sem validação no banco | `Ocorrencia::registrar` | Corrigido: só conta validada (nome + CPF válido + endereço), verificado pelo **banco** (CHECK + view + procedimento; gatilhos como camada extra) |
| 4 | **Alta** | Com `php -S` (ou hospedagens sem Apache) os `.htaccess` não valem: `database/schema.sql`, `.env` e código-fonte ficavam baixáveis | raiz do projeto | Corrigido: `router.php` bloqueia pastas internas, arquivos ocultos e `.sql/.md/.json` |
| 5 | **Média** | Sem cabeçalhos de segurança nas páginas (CSP, X-Frame-Options, Referrer-Policy, HSTS) | servidor | Corrigido: `.htaccess` (Apache) e `security-headers.php` (php -S) |
| 6 | **Média** | Sem verificação de origem: CSRF dependia só do token | API | Corrigido: POST/PUT/DELETE de outra origem → 403 (cabeçalho `Origin` / `Sec-Fetch-Site`), além do token CSRF |
| 7 | **Média** | Sessão expirava só por tempo máximo; sem expiração por inatividade; sessões ilimitadas por conta | `Sessao` | Corrigido: inatividade de 2 h, máximo 8 h, até 5 sessões por conta, cookie `SameSite=Strict` e `__Host-` em HTTPS |
| 8 | **Média** | Validação de dados só no PHP | banco | Corrigido: `CHECK`, `UNIQUE`, gatilhos e função de CPF no banco |
| 9 | **Média** | Política de senha fraca (só 8 caracteres) e login com tempo de resposta diferente para e-mail inexistente | `Usuario` | Corrigido: senha comum, com e-mail ou nome é recusada; limite de 72 bytes (bcrypt); verificação com hash falso para tempo igual |
| 10 | **Média** | Senhas e configuração do banco dentro do código | `config/config.php` | Corrigido: `.env` (fora do Git) com `.env.example` |
| 11 | **Baixa** | Script inline no `admin.html` (impedia CSP estrita) | `admin.html` | Corrigido: movido para `admin.js` |
| 12 | **Baixa** | Cadastro em massa a partir do mesmo IP | `register` | Corrigido: máximo 5 contas por IP por hora |
| 13 | **Baixa** | Usuário do banco com todos os privilégios (`root`) | XAMPP | Mitigado: `database/usuario_app.sql` cria usuário sem `INSERT` em `ocorrencia` |

Já estavam corretos e foram mantidos: consultas preparadas (PDO, `ATTR_EMULATE_PREPARES=false`), `password_hash()`/`password_verify()`,
token de sessão de 256 bits com só o hash SHA-256 no banco, cookie `HttpOnly`, token CSRF comparado com `hash_equals`,
escape de todo texto do usuário antes de `innerHTML`, nenhum `include` com entrada externa e nenhuma chamada a comando do sistema.

---

## 2. SQL Injection

**Regra:** nenhum dado vindo do usuário entra no texto do SQL. Todo valor vai por parâmetro.

```php
// src/Database.php
new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,   // o banco prepara de verdade: o valor nunca vira SQL
]);

// src/FiltroOcorrencia.php: o filtro só monta trechos fixos; os valores vão em $params
$where[] = 'c.slug = ?';  $params[] = $this->categoria;   // e a categoria é validada contra a tabela antes
```

**Por quê:** com consulta preparada, `' OR '1'='1` é só um texto comparado com o e-mail, nunca um comando. Testado: login e filtro com essas entradas retornam 401 e 400.

---

## 3. XSS (saída e entrada)

- **Saída:** todo texto do usuário passa por `escapeHTML()` / `esc()` antes de entrar no HTML (`dashboard.js`, `admin.js`, `auth.js`). Descrição e endereço são exibidos como texto.
- **API:** responde sempre `application/json` com `JSON_HEX_TAG | JSON_HEX_AMP` e `X-Content-Type-Options: nosniff` (o navegador não interpreta a resposta como HTML).
- **Entrada:** o servidor limita tamanho (`Request::texto($campo, $max)`), normaliza (CPF/CEP só dígitos, UF em lista fechada) e o banco reforça com `CHECK`.
  O texto **não** é "limpo" na entrada: guarda-se o que a pessoa escreveu e escapa-se na saída. Isso evita dupla codificação e não perde dados.
- **CSP** (seção 7) é a segunda barreira se algum escape falhar.

---

## 4. CSRF, sessões e cookies

```php
// src/Sessao.php: cookie da sessão
setcookie($nome, $token, [
    'expires'  => $expira,
    'path'     => '/',
    'httponly' => true,          // JavaScript não lê o cookie
    'samesite' => 'Strict',      // o navegador não envia o cookie em requisições vindas de outro site
    'secure'   => Request::https(),
]);
// em HTTPS o nome vira __Host-ocorrencias_sid: o navegador exige Secure, Path=/ e impede subdomínio de sobrescrever
```

- **Token CSRF por sessão**, enviado pelo `api.js` no cabeçalho `X-CSRF-Token` e conferido com `hash_equals` em todo POST, PUT e DELETE.
- **Verificação de origem** (`Request::exigirMesmaOrigem`): requisição que altera dado vinda de outro domínio → 403.
- **Fixação de sessão:** todo login gera token novo; logout apaga a sessão no banco.
- **Expiração:** 2 h sem uso ou 8 h no total (`.env`: `SESSION_IDLE_MINUTES`, `SESSION_HOURS`); no máximo 5 sessões abertas por conta.
- **Banco guarda só `SHA-256(token)`**: quem ler a tabela `sessao` não consegue usar as sessões.

---

## 5. Senhas e autenticação

| Controle | Implementação |
|---|---|
| Hash | `password_hash($senha, PASSWORD_DEFAULT)` (bcrypt hoje; troca automática com `password_needs_rehash`) |
| Política | 8 a 72 bytes; recusa senhas comuns e senhas que contêm o e-mail ou o nome |
| Força bruta | `LimiteLogin`: 5 falhas por e-mail ou 20 por IP em 15 min → 429 + `Retry-After`; tentativas na tabela `tentativa_login` (e-mail em hash) |
| Enumeração de contas | Mesma mensagem e tempo parecido para e-mail inexistente e senha errada (`password_verify` contra um hash falso) |
| Administrador | Sem senha padrão; criado ou promovido só pelo terminal (`php database/criar_admin.php`), que encerra as sessões da conta |
| Auditoria | `LOGIN`, `LOGIN_FALHOU`, `LOGIN_BLOQUEADO`, `ACESSO_NEGADO` em `registro_auditoria`, com IP |
| **MFA** | Não implementado nesta versão (decisão do grupo). Próximo passo sugerido: TOTP (app autenticador) obrigatório para administradores |

---

## 6. Validação no banco e alertas sem fraude (itens 9 e 10)

O banco é a última palavra: mesmo que alguém escreva direto nas tabelas, sem passar pelo PHP, a regra vale.

### Conta validada

Para criar alertas, a conta precisa de **nome, CPF válido e endereço completo** (CEP, rua, número, bairro, cidade, UF).
O usuário preenche em **Minha conta** (`public/perfil.html`); o CEP preenche o endereço pela ViaCEP.

| Regra | Onde |
|---|---|
| CPF com dígitos verificadores corretos | `CHECK ck_usuario_cpf` (cálculo dos dois dígitos dentro da própria regra) |
| Um CPF por conta | `UNIQUE (cpf)` |
| CPF não pode ser trocado depois de definido | procedimento `sp_atualizar_perfil` (`CPF_IMUTAVEL`) + gatilho `trg_usuario_bu` onde houver |
| Formato de CEP, UF da lista oficial, nome ≥ 3, e-mail com @ | `CHECK` na tabela `usuario` |
| "Conta validada" | view `vw_usuario_validado`, a mesma usada pelo PHP, pelo procedimento e pelo gatilho |

> **Versão 3 (compatível com bancos gerenciados):** a regra principal usa só `CHECK`, `VIEW` e `PROCEDURE`, que funcionam sem privilégio SUPER (testado com log binário ligado e usuário comum, como na Discloud). Os gatilhos ficaram como **camada extra** em `database/gatilhos_extra.sql`: o `importar.php` instala quando o servidor permite (XAMPP sim) e segue sem eles quando não permite.

### Criação de alerta: só pelo banco, com sessão e conta validadas

```sql
-- O PHP não faz INSERT: chama o procedimento passando o HASH do token da sessão
CALL sp_registrar_ocorrencia(:token_hash, :categoria, :descricao, :endereco, :lat, :lng, @id);
```

1. `sp_registrar_ocorrencia` procura a sessão pelo hash do token e confere se não expirou → senão `SESSAO_INVALIDA`.
2. O próprio procedimento (e, onde existir, o gatilho `trg_ocorrencia_bi`, que roda em **qualquer** INSERT, inclusive manual) confere:
   - conta validada (`PERFIL_INCOMPLETO`);
   - ponto dentro do Brasil (`FORA_DO_BRASIL`);
   - no máximo 10 alertas por conta por hora (`LIMITE_OCORRENCIAS`);
   - mesma conta, mesmo tipo, a menos de ~100 m em 30 min (`OCORRENCIA_DUPLICADA`);
   - a data é sempre a do servidor (ninguém registra "no passado").
3. O próprio procedimento grava a auditoria `CRIAR_OCORRENCIA`.
4. Com o usuário `app_ocorrencias` (`database/usuario_app.sql`, onde é possível criar usuários: XAMPP, VPS), a aplicação **não tem permissão de INSERT** em `ocorrencia`: o único caminho é o procedimento.
   Em bancos gerenciados sem gatilhos nem `GRANT` (Discloud), o procedimento valida tudo o que o sistema grava; um INSERT manual de quem tem a senha do banco não passaria pelas regras de limite e repetição (o CHECK do CPF e as chaves continuam valendo).

`src/DbErro.php` traduz cada recusa do banco em resposta HTTP com mensagem clara (401, 403, 409, 422, 429).

**Testado:** sem perfil → 403; CPF inválido → 422; CPF de outra conta → 409; troca de CPF → 422; repetido → 409; fora do Brasil → 400;
11º alerta na hora → 429; `INSERT` manual com conta incompleta → `PERFIL_INCOMPLETO`; procedimento com token falso → `SESSAO_INVALIDA`;
`INSERT` com o usuário `app_ocorrencias` → `INSERT command denied`.

> **Limite honesto:** um CPF válido prova que a conta tem um CPF real, não que ele pertence a quem digitou.
> A regra bloqueia contas descartáveis em massa (um CPF por conta, limite por IP e por hora), mas não fraude com CPF de terceiros.
> Para identidade forte: login gov.br, confirmação por SMS ou moderação dos alertas pelo administrador.

### LGPD (CPF e endereço)

- O CPF é usado só para impedir contas falsas: nunca aparece para outras pessoas; a API devolve `***.***.***-25` até para o próprio dono; o painel admin não mostra CPF nem endereço.
- Informe isso numa política de privacidade antes de publicar e ofereça exclusão da conta (o `ON DELETE CASCADE` já remove sessões e ocorrências do usuário).
- Em produção, considere criptografar CPF e endereço em repouso (ou guardar só um HMAC do CPF para a regra de unicidade).

---

## 7. Cabeçalhos HTTP de segurança

Aplicados pelo `.htaccess` (Apache/XAMPP/hospedagens), pelo `security-headers.php` via `router.php` (php -S) e pelo `bootstrap.php` (API).

| Cabeçalho | Valor | Por quê |
|---|---|---|
| `Content-Security-Policy` (login, cadastro, admin, perfil) | `default-src 'self'; script-src 'self'; …; frame-ancestors 'none'` | Só roda script do próprio site: XSS injetado não executa |
| `Content-Security-Policy` (mapa e home) | lista do guia de CSP do Google Maps | O Google Maps exige `unsafe-inline`/`unsafe-eval`; por isso a CSP estrita fica nas páginas sem mapa |
| `Content-Security-Policy` (API) | `default-src 'none'; frame-ancestors 'none'` | Respostas JSON não carregam nada |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains` (só em HTTPS) | O navegador passa a usar sempre HTTPS |
| `X-Frame-Options` | `DENY` | Impede *clickjacking* (site dentro de iframe) |
| `X-Content-Type-Options` | `nosniff` | O navegador não "adivinha" o tipo do arquivo |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | Não vaza URLs internas para outros sites |
| `Permissions-Policy` | `camera=(), microphone=(), payment=(), usb=(), geolocation=(self)` | Desliga recursos que o site não usa |
| `Cross-Origin-Opener-Policy` | `same-origin` | Isola a janela de páginas de outros sites |
| `X-Powered-By` | removido | Não anuncia a versão do PHP |

---

## 8. Inclusão de arquivos (LFI/RFI) e Command Injection

- **Nenhum `include`/`require` usa entrada do usuário.** O autoload só aceita nomes de classe `^[A-Z][A-Za-z0-9]{1,60}$` (bloqueia `../`, `\`, `:`).
- **Nenhuma chamada a comandos do sistema** (`exec`, `system`, `shell_exec`, `passthru`, `proc_open`). Desative-as no `php.ini` (abaixo).
- `allow_url_include = Off` impede RFI mesmo se surgir um `include` errado no futuro.
- O `router.php` resolve o caminho com `realpath` e só entrega arquivos dentro da raiz do projeto (testado: `/public/../config/config.php` → 404).

---

## 9. `php.ini` recomendado para produção

Na hospedagem compartilhada, use o painel ("Configurações do PHP") ou um arquivo `.user.ini` na raiz, se o host permitir.

```ini
; ---- não expor detalhes ----
expose_php = Off
display_errors = Off
display_startup_errors = Off
log_errors = On
error_log = /caminho/fora/do/public_html/php-error.log
error_reporting = E_ALL

; ---- arquivos e rede ----
allow_url_fopen = Off            ; o back-end não busca URLs externas
allow_url_include = Off
file_uploads = Off               ; o sistema não recebe uploads (ligue só se criar essa função)
open_basedir = /caminho/do/projeto:/tmp
disable_functions = exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec,show_source

; ---- sessões nativas (o sistema usa sessão própria, mas fica seguro se alguém usar session_start) ----
session.use_strict_mode = 1
session.use_only_cookies = 1
session.use_trans_sid = 0
session.cookie_httponly = 1
session.cookie_secure = 1
session.cookie_samesite = Strict
session.sid_length = 48
session.sid_bits_per_character = 6

; ---- limites ----
max_execution_time = 30
max_input_time = 30
memory_limit = 128M
post_max_size = 1M
max_input_vars = 200
```

Confira com `php -i | findstr /i "display_errors expose_php allow_url"` (Windows) ou criando temporariamente um `phpinfo()` (e apagando depois).

---

## 10. Dependências (Composer) e variáveis de ambiente

- O projeto **não tem dependências de terceiros no back-end**: menos código de fora, menos vulnerabilidades. O `composer.json` só declara a versão do PHP e as extensões exigidas (`pdo_mysql`, `mbstring`).
- Se adicionar pacotes: versione o `composer.lock`, rode `composer audit` antes de cada entrega e `composer install --no-dev --optimize-autoloader` no servidor. A pasta `vendor/` fica fora do Git e é bloqueada pelo `router.php`.
- **`.env`** (copie de `.env.example`): senhas do banco e parâmetros de sessão. Está no `.gitignore`, é bloqueado pelo `.htaccess` e pelo `router.php`, e variáveis reais do servidor têm prioridade sobre ele.
- Em produção: `APP_ENV=production`, `APP_DEBUG=false`, `DB_USER=app_ocorrencias` com senha forte.
- Front-end: Bootstrap Icons e Google Maps vêm de CDN. Para fixar a integridade do arquivo de ícones, adicione `integrity="sha384-…"` e `crossorigin="anonymous"` no `<link>` (o hash é publicado no jsDelivr).

---

## 11. Checklist antes de publicar

- [ ] HTTPS ativo e redirecionamento forçado (descomentar no `.htaccess`)
- [ ] `.env` com `APP_DEBUG=false` e usuário `app_ocorrencias` (`database/usuario_app.sql`, senha trocada)
- [ ] Administrador criado com `criar_admin.php` e senha forte; `admin@exemplo.com` antigo removido ou com senha trocada
- [ ] `php.ini` da seção 9 aplicado
- [ ] Chave do Google Maps restrita ao domínio
- [ ] Política de privacidade (CPF e endereço) publicada
- [ ] `config/config.php`, `.env` e `js/config.js` fora do Git (`git status` não deve listá-los)
