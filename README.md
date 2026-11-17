# Sistema de Monitoramento e Sinalização de Ocorrências Ambientais

> Trabalho acadêmico da A3 de **Modelos, Métodos e Técnicas da Engenharia de Software**.
> **No ar:** https://moniambiental.discloud.app

## Sobre o projeto

A aplicação permite que a população registre e visualize ocorrências ambientais diretamente em um **mapa interativo**. Ao identificar uma situação de risco em uma rua ou região, o usuário marca o local no mapa e sinaliza a ocorrência para que todos tenham conhecimento.

Tipos de ocorrência monitorados:

- 🌊 **Alagamento / enchente**
- 🏔️ **Erosão**
- ⚠️ **Queda de talude**

O objetivo é criar uma plataforma colaborativa que facilite a **identificação, visualização e compartilhamento de informações sobre problemas ambientais nas cidades**, aumentando a conscientização sobre situações que causam riscos à comunidade.

Para evitar **alertas falsos**, só contas autenticadas e **validadas** (nome, CPF válido e endereço completo) podem criar alertas, e quem decide isso é o **próprio banco de dados**, não apenas o código PHP.

---

## Funcionalidades

### Público (sem login)

- Página inicial de apresentação do projeto;
- Mapa com todas as ocorrências, com filtro por tipo e período;
- Nenhum dado pessoal de quem registrou aparece no mapa.

### Usuário

- Cadastro, login e logout;
- **Minha conta:** nome, CPF e endereço (com preenchimento automático pelo CEP via ViaCEP);
- Registro de ocorrência: clicar no mapa, escolher o tipo e descrever;
- Tema claro e escuro (vale também para o mapa).

### Administrador

- Lista de usuários com contagem de ocorrências e situação da conta (validada / pendente);
- Edição e exclusão de ocorrências (com 5 segundos para desfazer);
- Consulta do registro de auditoria (logs);
- Limpeza de ocorrências antigas.

### Regras validadas pelo banco (procedure `sp_registrar_ocorrencia`)

Todo alerta passa por estas regras dentro do MySQL antes de ser gravado:

| Regra | Se falhar |
|---|---|
| Sessão válida e não expirada | 401 |
| Conta validada (nome + CPF + endereço) | 403 |
| Tipo de ocorrência existente | 400 |
| Ponto dentro do território brasileiro | 400 |
| Máximo de 10 alertas por hora por usuário | 429 |
| Sem alerta repetido no mesmo ponto em 30 min | 409 |

O CPF também é conferido pelo banco (cálculo dos dígitos verificadores em uma `CHECK`), não pode se repetir e não pode ser alterado depois de salvo.

---

## Arquitetura

O projeto separa as responsabilidades em camadas:

> **`public/`, `css/`, `js/` → Interface do usuário**
> **`api/` → Comunicação HTTP (REST/JSON)**
> **`src/` → Regras de negócio e segurança**
> **`database/` → Estrutura e regras dos dados**
> **`config/` → Configurações da aplicação**

```text
Usuário → Frontend (public/, js/) → Requisição HTTP → API (api/) → Domínio (src/) → MySQL (procedures, view, CHECKs)
```

### Estrutura de pastas

```text
├── api/
│   ├── auth/            register, login, logout, me, profile
│   ├── incidents/       index.php (GET lista / POST cria)
│   ├── admin/           users, incidents, logs, purge
│   └── controllers/     AuthController, OcorrenciaController, AdminController
│
├── src/                 camada de domínio (PHP puro, orientado a objetos)
│   ├── bootstrap.php    carregado por todo endpoint: config, autoload, cabeçalhos, erros
│   ├── Database.php     conexão PDO (Singleton)
│   ├── Usuario.php      cadastro, login, perfil, validação de CPF e senha
│   ├── Ocorrencia.php   registro (via procedure), listagem, edição, exclusão
│   ├── Categoria.php, FiltroOcorrencia.php, RegistroAuditoria.php
│   ├── Sessao.php       token de sessão, CSRF, expiração
│   ├── LimiteLogin.php  limite de tentativas de login e cadastro
│   ├── Request.php, Response.php, HttpException.php, DbErro.php, env.php
│
├── database/
│   ├── schema.sql           tabelas, CHECKs, view e procedures
│   ├── gatilhos_extra.sql   gatilhos opcionais (camada extra)
│   ├── migracao_v2_seguranca.sql, migracao_v3.sql
│   ├── importar.php         monta o banco pelo terminal
│   ├── criar_admin.php      cria ou promove um administrador
│   ├── diagnostico.php      testa a conexão com o banco passo a passo
│   ├── backup.php, restaurar.php
│   └── usuario_app.sql      usuário do banco com privilégio mínimo
│
├── public/              home, index (mapa), login, register, perfil, admin
├── css/                 base, home, dashboard, auth, admin
├── js/                  api, auth, forms, theme, maps, dashboard, perfil, admin, home
├── fonts/, img/
├── config/config.example.php   modelo da configuração (copie para config.php)
├── js/config.example.js        modelo da chave do Google Maps (copie para config.js)
├── .env.example                modelo das variáveis de ambiente
├── router.php                  roteador para o servidor embutido do PHP
├── .htaccess, index.php        regras para Apache
└── discloud.config             configuração da hospedagem
```

### Banco de dados

| Tabela | Conteúdo |
|---|---|
| `usuario` | nome, e-mail, hash da senha, perfil, CPF e endereço |
| `sessao` | hash do token de sessão, token CSRF, expiração |
| `tentativa_login` | tentativas de login e cadastro (limite contra força bruta) |
| `categoria` | alagamento, erosão, talude |
| `ocorrencia` | tipo, descrição, endereço, latitude, longitude, data e hora |
| `registro_auditoria` | quem fez o quê e quando |

Além das tabelas: a view `vw_usuario_validado` (diz se a conta está completa) e as procedures `sp_registrar_ocorrencia` e `sp_atualizar_perfil`.

## Documentação complementar

- **[SEGURANCA.md](SEGURANCA.md):** vulnerabilidades tratadas, correções e configurações de produção.
- **[DISCLOUD.md](DISCLOUD.md):** passo a passo da publicação e do banco na Discloud.

---

## Publicação (Discloud)

O site está hospedado na **Discloud** (plano Diamond), com o banco **MySQL** na rede privada (VLAN), sem acesso pela internet.

---

## Tecnologias

| Camada | Tecnologias |
|---|---|
| Backend | PHP 8.1+ (sem framework), PDO, API REST, JSON |
| Banco | MySQL 8/9 ou MariaDB: CHECK, VIEW, PROCEDURE |
| Frontend | HTML5, CSS3, JavaScript, Bootstrap Icons |
| Mapas | Google Maps JavaScript API, Geocoding API, Nominatim |
| Outros serviços | ViaCEP (endereço pelo CEP) |
| Hospedagem | Discloud (Apache + PHP, MySQL gerenciado) |

---

## Versões

| Versão | O que mudou |
|---|---|
| `v0.0.1` | Primeira versão: frontend com mapa, tema escuro/claro e layout do painel |
| `v0.1.0` | Backend PHP + MySQL, API REST, validação de alertas pelo banco, camada de segurança, redesign com página inicial e perfil, deploy na Discloud |

O projeto segue o [versionamento semântico](https://semver.org/lang/pt-BR/): correção → `v0.1.1`, funcionalidade nova → `v0.2.0`.

---

## Objetivo acadêmico

O projeto aplica conhecimentos da graduação em:

- Desenvolvimento web e arquitetura de software em camadas;
- Programação orientada a objetos e modelagem UML (casos de uso, classes e sequência);
- Desenvolvimento de APIs REST e comunicação frontend ↔ backend;
- Banco de dados relacional com regras de integridade no próprio banco;
- Autenticação, controle de sessão e segurança em aplicações web;
- Versionamento com Git e publicação em nuvem;
- Uso da tecnologia na solução de problemas ambientais.

Além do aspecto técnico, o projeto mostra como uma aplicação web pode **conectar a população a informações importantes sobre problemas ambientais nas cidades**, transformando sinalizações individuais em um painel coletivo.
