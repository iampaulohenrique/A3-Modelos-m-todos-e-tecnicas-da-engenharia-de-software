# Publicar na Discloud (plano Diamond)

O projeto já vem pronto para a Discloud:

- **`discloud.config`:** site na porta 8080, rodando `php -S 0.0.0.0:8080 router.php`, com VLAN e reinício automático.
- **`router.php`:** faz o papel do Apache: bloqueia `.env`, `config/`, `src/`, `database/`, `backups/` e envia os cabeçalhos de segurança.
- **`TRUST_PROXY`:** atrás do proxy da Discloud, faz o PHP enxergar o HTTPS e o IP real de cada pessoa (usado no limite de tentativas de login).
- **`database/importar.php`, `backup.php`, `restaurar.php`:** cuidam do banco pelo console, sem precisar de phpMyAdmin.

O banco usa só recursos que funcionam com o usuário comum da Discloud (sem privilégio SUPER): `CHECK`, `VIEW` e `PROCEDURE`.
Se o servidor não permitir gatilhos, o importador avisa e segue: o procedimento continua validando todo alerta criado pelo sistema.

---

## 1. Criar o banco MySQL

1. Painel da Discloud → **Databases** → **Create database** → **MySQL**.
2. Nome do serviço: ex. `ocorrencias-db`. Nome do banco: `ocorrencias_ambientais`. Usuário: ex. `app` (não pode ser `root`). Senha forte.
3. RAM: **512 MB** (mínimo para MySQL).
4. Espere ficar **Online** e abra **Overview → Private connection**. Copie a **URL** (formato `mysql://usuario:senha@host:porta/banco`).

> Use um **Project** na Discloud com o banco e o site juntos, para os dois ficarem no mesmo cluster (exigência da conexão privada).

## 2. Criar o subdomínio

Painel → crie um subdomínio, ex. `monitoramento-a3`. O site vai abrir em `https://monitoramento-a3.discloud.app`.

## 3. Ajustar os arquivos antes de enviar

**`discloud.config`**: troque o `ID` pelo seu subdomínio (só o nome, sem `.discloud.app`):

```properties
ID=monitoramento-a3
```

**`.env`**: crie o arquivo na raiz do projeto, com a URL copiada do banco:

```ini
APP_ENV=production
APP_DEBUG=false
DB_URL=mysql://app:SUA-SENHA@HOST-DA-VLAN:PORTA/ocorrencias_ambientais
TRUST_PROXY=true
SESSION_HOURS=8
SESSION_IDLE_MINUTES=120
```

- Se a senha tiver `@`, `:`, `/` ou `#`, troque esses caracteres por `%40`, `%3A`, `%2F` e `%23` na URL.
- Se o painel da Discloud tiver um lugar para variáveis de ambiente, pode colocar `DB_URL` e `TRUST_PROXY` lá em vez do `.env`.
- O `.env` é bloqueado pelo `router.php`: ninguém consegue baixá-lo pelo navegador.

**`js/config.js`**: coloque a chave do Google Maps. No Google Cloud, em Referenciadores HTTP, libere também:

```
https://monitoramento-a3.discloud.app/*
```

## 4. Enviar o projeto

1. Compacte a pasta do projeto em `.zip`, com os arquivos na **raiz** do zip: `discloud.config`, `router.php`, `composer.json`, `api/`, `public/`…
   O `.discloudignore` já tira `.git`, `backups/` e outros arquivos que não precisam ir.
2. Painel → **Add app** (ou **Upload**) → envie o zip. Também dá pelo GitHub (Auto deploy), mas aí o `.env` e o `js/config.js`, que estão no `.gitignore`, não vão: use as variáveis de ambiente do painel.
3. Confira se o app está com **VLAN ligada** (o `discloud.config` já pede `VLAN=true`).

## 5. Montar o banco pelo console

Abra o **Console interativo** do app e rode, um de cada vez:

```bash
php database/importar.php
```

A saída esperada:

```
Importando schema.sql no banco "ocorrencias_ambientais"…
13 comandos executados.
Instalando gatilhos (camada extra)… instalados.        ← ou: "o servidor não permite gatilhos…" (tudo bem)
Tabelas: categoria, ocorrencia, registro_auditoria, sessao, tentativa_login, usuario, vw_usuario_validado
Procedimentos: sp_atualizar_perfil, sp_registrar_ocorrencia
```

Depois crie o administrador:

```bash
php database/criar_admin.php --nome="Seu Nome" --email=voce@exemplo.com --senha="UmaSenhaForte#2026"
```

Se o console aceitar digitação, rode só `php database/criar_admin.php` e responda às perguntas: assim a senha não fica no histórico.

## 6. Testar

1. Abra `https://monitoramento-a3.discloud.app/`. A raiz já leva para a página inicial.
2. Entre com o administrador e complete **CPF e endereço** em **Minha conta**. Sem isso, nem o admin cria alertas.
3. Marque um ponto no mapa e adicione um alerta.

## 7. Backup (o plano Diamond não tem backup automático)

Antes de apresentar, ou depois de mudanças importantes, rode no console:

```bash
php database/backup.php
```

O arquivo aparece em `backups/` no **Explorador de arquivos**. Baixe e **apague do servidor**, porque ele contém CPF e endereço dos usuários.

Para restaurar, envie o arquivo para `backups/` e rode:

```bash
php database/restaurar.php --arquivo=backups/backup-AAAAMMDD-HHMMSS.sql
```

---

## Se der erro

| Mensagem | Causa provável | O que fazer |
|---|---|---|
| "Não foi possível conectar ao banco de dados" | `DB_URL` errada, VLAN desligada ou app e banco em clusters diferentes | Confira a URL da conexão privada, a VLAN e coloque os dois no mesmo Project |
| `could not find driver` | A imagem PHP da Discloud está sem a extensão `pdo_mysql` | Fale com o suporte da Discloud. Pode ser preciso um pacote extra no `APT=` do `discloud.config` |
| O site não abre ou dá 502 | App não está ouvindo na porta 8080 ou o `ID` não bate com o subdomínio | Confira o `START` e o `ID` no `discloud.config` e veja o log do app |
| "Requisição de outra origem recusada" ao entrar | `TRUST_PROXY` não está `true` | Ajuste o `.env` e reinicie o app |
| Mapa sem ruas ou erro no console do navegador | Chave do Google sem o domínio `.discloud.app` | Adicione o domínio nos Referenciadores HTTP da chave |
| Erro de privilégio no `importar.php` (fora dos gatilhos) | O usuário do banco não pode criar VIEW ou PROCEDURE | Fale com o suporte (essa parte é obrigatória) |
