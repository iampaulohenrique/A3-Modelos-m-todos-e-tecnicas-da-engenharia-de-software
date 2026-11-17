# Sistema de Monitoramento e Sinalização de Ocorrências Ambientais

## Sobre o projeto

Este projeto foi desenvolvido como trabalho acadêmico com foco na **área ambiental e na participação da população na identificação de problemas urbanos**.

A aplicação permite que usuários registrem e visualizem ocorrências ambientais diretamente em um **mapa interativo**. Ao identificar uma situação de risco ou problema em determinada rua ou região, o usuário pode selecionar o local no mapa e sinalizar a ocorrência para que outros usuários da plataforma tenham conhecimento.

Os principais tipos de ocorrências monitorados pelo sistema são:

- 🌊 **Enchentes**
- 🏔️ **Erosão**
- ⚠️ **Queda de talude**

O objetivo é criar uma plataforma colaborativa que facilite a **identificação, visualização e compartilhamento de informações sobre problemas ambientais nas cidades**, contribuindo para uma maior conscientização da população sobre situações que podem causar riscos e impactos à comunidade.

---

## Funcionalidades

### Usuários

- Cadastro de novos usuários;
- Login e logout;
- Gerenciamento de sessão;
- Proteção contra CSRF;
- Controle de acesso baseado em papéis;
- Visualização do perfil do usuário autenticado;
- Criação de ocorrências ambientais;
- Visualização das ocorrências registradas no mapa;
- Exclusão de ocorrências;
- Contagem de ocorrências cadastradas por usuário.

### Ocorrências ambientais

O usuário pode acessar o mapa, localizar uma rua ou região e registrar uma ocorrência ambiental naquele ponto.

Cada sinalização representa um evento identificado pelo usuário, como:

- Uma área com histórico ou ocorrência de **enchente**;
- Um local afetado por **erosão**;
- Uma região com risco ou ocorrência de **queda de talude**.

Dessa forma, o mapa funciona como um painel colaborativo de informações, permitindo que os usuários tenham uma visão geral das ocorrências registradas na cidade.

### Administração

O sistema também possui uma área administrativa destinada ao gerenciamento da aplicação, incluindo:

- Visualização de usuários;
- Consulta de logs do sistema;
- Controle de informações administrativas.

---

# Arquitetura do Projeto

A aplicação foi organizada separando as responsabilidades entre **configuração, banco de dados, regras de negócio, API e interface pública**.

```text
projeto/
├── config/
│   └── config.php
│
├── database/
│   └── schema.sql
│
├── src/
│   ├── Database.php
│   ├── Response.php
│   ├── Auth.php
│   └── Occurrence.php
│
├── api/
│   ├── auth/
│   │   ├── register.php
│   │   ├── login.php
│   │   ├── logout.php
│   │   └── me.php
│   │
│   ├── occurrences/
│   │   ├── list.php
│   │   ├── create.php
│   │   └── delete.php
│   │
│   └── admin/
│       ├── users.php
│       └── logs.php
│
└── public/
    ├── index.html
    ├── login.html
    ├── register.html
    ├── admin.html
    │
    ├── css/
    │   ├──admin.css
    │   ├──admin.css
    │   ├──auth.css
    │   ├──base.css
    │   ├──dashboard.css
    │   ├──styles.css
    │
    └── js/
        ├── api.js
        ├── auth.js
        ├── dashboard.js
        └── admin.js
```

---

## Estrutura das Pastas

### `config/`

Contém as configurações gerais do sistema.

#### `config.php`

Arquivo responsável por centralizar configurações como:

- Credenciais de acesso ao banco de dados;
- Configuração de sessão;
- Timezone da aplicação;
- Outras configurações necessárias para o funcionamento do sistema.

---

### `database/`

Contém os arquivos relacionados à estrutura do banco de dados.

#### `schema.sql`

Responsável pela criação das tabelas e estrutura completa do banco de dados.

Também pode incluir dados iniciais, como a criação de um usuário administrador (**admin seed**).

---

### `src/`

Representa a **camada de domínio da aplicação**, onde estão concentradas as principais regras de negócio.

As classes dessa pasta utilizam PHP puro e são responsáveis pela lógica principal do sistema.

#### `Database.php`

Responsável pela conexão com o banco de dados utilizando **PDO**.

A conexão utiliza o padrão **Singleton**, garantindo um ponto centralizado para acesso ao banco de dados.

#### `Response.php`

Classe auxiliar responsável por padronizar as respostas da API em formato **JSON**.

Isso permite que os endpoints retornem informações de maneira organizada e consistente.

#### `Auth.php`

Responsável pelas funcionalidades relacionadas à autenticação e segurança dos usuários:

- Registro;
- Login;
- Logout;
- Controle de sessão;
- Proteção CSRF;
- Controle de papéis e permissões.

#### `Occurrence.php`

Responsável pelas regras relacionadas às ocorrências ambientais.

Principais responsabilidades:

- Criar ocorrências;
- Listar ocorrências;
- Excluir ocorrências;
- Associar ocorrências aos usuários;
- Realizar a contagem de ocorrências cadastradas por cada usuário.

---

### `api/`

A pasta `api` representa a **porta de entrada HTTP da aplicação**.

Os arquivos presentes nela recebem as requisições do frontend, utilizam as classes da camada `src` e retornam as informações em formato JSON.

#### Autenticação

```text
api/auth/
├── register.php
├── login.php
├── logout.php
└── me.php
```

Endpoints responsáveis pelo gerenciamento da autenticação dos usuários.

#### Ocorrências

```text
api/occurrences/
├── list.php
├── create.php
└── delete.php
```

Endpoints responsáveis pelas operações relacionadas às ocorrências ambientais.

#### Administração

```text
api/admin/
├── users.php
└── logs.php
```

Endpoints utilizados pela área administrativa para gerenciamento e consulta de informações do sistema.

---

### `public/`

Contém todos os arquivos acessados diretamente pelo navegador.

Essa camada representa o **frontend da aplicação**.

#### Páginas principais

| Arquivo | Descrição |
|---|---|
| `index.html` | Dashboard principal com o mapa e as ocorrências |
| `login.html` | Página de autenticação |
| `register.html` | Página de cadastro de usuários |
| `admin.html` | Painel administrativo |

#### CSS

```text
public/css/
└── styles.css
```

Responsável pela estilização e aparência visual da aplicação.

#### JavaScript

```text
public/js/
├── api.js
├── auth.js
├── dashboard.js
└── admin.js
```

Cada arquivo possui uma responsabilidade específica:

- **api.js:** comunicação entre o frontend e a API;
- **auth.js:** gerenciamento da autenticação no frontend;
- **dashboard.js:** funcionamento do mapa e das ocorrências;
- **admin.js:** funcionalidades do painel administrativo.

---

# Fluxo de Funcionamento

O funcionamento da aplicação segue o fluxo abaixo:

```text
Usuário
   ↓
Frontend
(public/)
   ↓
Requisição HTTP
   ↓
API
(api/)
   ↓
Regras de Negócio
(src/)
   ↓
Banco de Dados
(database/)
```

### Exemplo de sinalização de uma ocorrência

1. O usuário realiza o login na plataforma;
2. Acessa o dashboard principal;
3. Visualiza o mapa da região;
4. Localiza a rua ou área onde identificou um problema ambiental;
5. Clica no ponto desejado do mapa;
6. Seleciona o tipo de ocorrência;
7. A ocorrência é enviada para a API;
8. A API processa os dados através da camada de domínio;
9. As informações são armazenadas no banco de dados;
10. O ponto sinalizado passa a ficar disponível para visualização dos usuários da aplicação.

---

# Tecnologias Utilizadas

O projeto utiliza uma arquitetura baseada em tecnologias web:

### Backend

- **PHP**
- **PDO**
- **API REST**
- **Sessões**
- **JSON**

### Frontend

- **HTML5**
- **CSS3**
- **JavaScript**

### Banco de Dados

- Banco de dados relacional;
- Estrutura definida através do arquivo `schema.sql`.

---

# Objetivo Acadêmico

Este projeto foi desenvolvido com o objetivo de aplicar conhecimentos adquiridos durante a graduação, envolvendo conceitos como:

- Desenvolvimento Web;
- Arquitetura de Software;
- Separação de Responsabilidades;
- Programação Orientada a Objetos;
- Desenvolvimento de APIs;
- Comunicação entre Frontend e Backend;
- Autenticação e Controle de Sessão;
- Segurança em aplicações web;
- Banco de Dados;
- CRUD;
- Sistemas colaborativos;
- Aplicação da tecnologia na solução de problemas ambientais.

Além do aspecto técnico, o projeto busca demonstrar como uma aplicação web pode ser utilizada para **conectar a população a informações importantes sobre problemas ambientais presentes nas cidades**.

---

# Objetivo do Sistema

A proposta principal é disponibilizar uma plataforma onde os próprios usuários possam colaborar com a identificação de situações ambientais que afetam suas regiões.

Ao permitir a marcação de ocorrências como **enchentes, erosões e quedas de talude diretamente no mapa**, o sistema transforma informações individuais em um painel coletivo de visualização.

Assim, a aplicação pode ajudar os usuários a:

- Identificar áreas com problemas ambientais;
- Visualizar ocorrências registradas por outros usuários;
- Compartilhar informações sobre situações de risco;
- Aumentar a conscientização ambiental;
- Facilitar a visualização geográfica dos problemas enfrentados pelas cidades.

---

## Estrutura e Organização

O projeto foi desenvolvido seguindo uma organização que busca facilitar sua manutenção e evolução:

> **`public` → Interface do usuário**  
> **`api` → Comunicação HTTP**  
> **`src` → Regras de negócio**  
> **`database` → Estrutura dos dados**  
> **`config` → Configurações da aplicação**

Essa separação permite uma arquitetura mais organizada, evitando que regras de negócio, acesso ao banco de dados e interface do usuário fiquem concentrados no mesmo local.

---

## Autor

Projeto desenvolvido como **trabalho acadêmico**, com foco na aplicação de tecnologias web para a criação de uma solução voltada ao **monitoramento colaborativo de ocorrências ambientais urbanas**.
---

## Interface (redesenho)

A interface foi redesenhada com um sistema visual único para todas as telas:

- **Tema claro e escuro:** botão em todas as páginas; a escolha vale para a interface e para o mapa (`js/theme.js`). Sem escolha salva, segue o tema do sistema.
- **Google Maps:** os mapas usam a Maps JavaScript API (`js/maps.js`). A chave fica em `js/config.js`; o nome da rua vem do Geocoder da Google e, se ele falhar, do Nominatim (OpenStreetMap).
- **`public/home.html`:** nova página inicial pública, que apresenta o projeto e leva ao mapa e ao cadastro.
- **`css/base.css`:** cores, tipografia (Archivo e Inter, em `fonts/`), botões e campos compartilhados.
- **`js/forms.js`:** validação do login e do cadastro, com erro embaixo de cada campo.
- **`js/dashboard.js`:** o texto digitado pelo usuário é escapado antes de ir para a tela (evita injeção de HTML), a remoção de alerta tem 5 segundos para desfazer e não há mais `alert()`.

Os `id`s, os `name`s e os `action`s dos formulários continuam os mesmos, então a integração com a API em PHP não muda.

### Configurando a chave do Google Maps

1. Acesse o [Google Cloud Console](https://console.cloud.google.com/), crie um projeto e ative o faturamento (a Google dá um crédito mensal gratuito que cobre um projeto acadêmico).
2. Em **APIs e serviços → Biblioteca**, ative a **Maps JavaScript API** e a **Geocoding API**.
3. Em **APIs e serviços → Credenciais**, crie uma **chave de API**.
4. Restrinja a chave: em *Restrições de aplicativo*, escolha **Referenciadores HTTP** e adicione `http://localhost:*/*` e `http://127.0.0.1:*/*` (e o domínio, se publicar o site). Em *Restrições de API*, deixe só as duas APIs acima.
5. Copie `js/config.example.js` para `js/config.js` (se ainda não existir) e cole a chave no campo `GOOGLE_MAPS_API_KEY`. O `js/config.js` está no `.gitignore` e não vai para o GitHub; quem clonar o repositório cria o próprio a partir do modelo.
6. Abra o projeto por um servidor local (Live Server, `python -m http.server` ou `npx serve`), não com dois cliques no arquivo, para a restrição por endereço funcionar.

Sem chave, o mapa mostra um aviso no lugar e o restante da página continua funcionando.

---

## Como rodar com o back-end (XAMPP)

Requisitos: [XAMPP](https://www.apachefriends.org/) com PHP 8.1 ou mais novo (as versões atuais já vêm assim).

1. **Copie o projeto** para dentro de `C:\xampp\htdocs\` (no Windows). Exemplo: `C:\xampp\htdocs\a3\` com as pastas `api`, `src`, `public` etc. dentro.
2. **Ligue Apache e MySQL** no XAMPP Control Panel (botões *Start*).
3. **Crie o banco** pelo terminal, na pasta do projeto: `C:\xampp\php\php.exe database\importar.php`
   (ou pelo phpMyAdmin: importe `database/schema.sql` e depois `database/gatilhos_extra.sql`).
   **Banco de uma versão anterior?** `C:\xampp\php\php.exe database\importar.php --arquivo=database/migracao_v3.sql` (mantém os dados; se ainda está na versão 1, rode antes o `migracao_v2_seguranca.sql` no phpMyAdmin).
4. **Configure o back-end:** copie `config/config.example.php` para `config/config.php`. Com o XAMPP padrão (usuário `root` sem senha) não precisa mudar nada.
5. **Configure o mapa:** copie `js/config.example.js` para `js/config.js` e cole a chave do Google Maps (seção acima). Na chave, libere também `http://localhost/*`.
6. **Abra:** http://localhost/a3/public/home.html (troque `a3` pelo nome da sua pasta).

**Administrador:** não existe mais senha padrão. Crie o seu pelo terminal, na pasta do projeto:
`C:\xampp\php\php.exe database\criar_admin.php`
(Se você ainda tem o `admin@exemplo.com` da versão anterior, rode o mesmo comando com esse e-mail para trocar a senha.)

**Para criar alertas** a conta precisa estar validada: nome, CPF válido e endereço completo, em **Minha conta** (`public/perfil.html`). O banco recusa alertas de contas sem isso.

> Com o PHP do XAMPP pelo terminal (sem Apache): `C:\xampp\php\php.exe -S localhost:8000 router.php` e abra http://localhost:8000/. O `router.php` faz o papel do `.htaccess` (bloqueia pastas internas e envia os cabeçalhos de segurança); não rode sem ele.

### Rotas da API

As rotas seguem os diagramas de sequência; em PHP sem framework, cada uma é um arquivo:

| Rota (UML) | Arquivo | Acesso |
|---|---|---|
| `POST /auth/register` | `api/auth/register.php` | público |
| `POST /auth/login` | `api/auth/login.php` | público |
| `POST /auth/logout` | `api/auth/logout.php` | logado |
| `GET /auth/me` | `api/auth/me.php` | público (diz quem está logado) |
| `GET /incidents?categoria=&de=&ate=` | `api/incidents/` (GET) | público, sem dados pessoais |
| `POST /incidents` | `api/incidents/` (POST) | logado |
| `GET /admin/users` | `api/admin/users.php` | administrador |
| `PUT /admin/incidents/{id}` | `api/admin/incidents.php?id={id}` (PUT) | administrador |
| `DELETE /admin/incidents/{id}` | `api/admin/incidents.php?id={id}` (DELETE) | administrador |
| `GET /admin/logs` | `api/admin/logs.php` | administrador |
| `POST /admin/purge` | `api/admin/purge.php` | administrador |

Respostas sempre em JSON; erros vêm como `{"erro": "mensagem", "campos": {...}}` com o status HTTP certo (400, 401, 403, 404, 409, 422).

### Segurança implementada

Relatório completo (vulnerabilidades, correções e configurações de produção): **[SEGURANCA.md](SEGURANCA.md)**.
Publicação na Discloud: **[DISCLOUD.md](DISCLOUD.md)**.


- Senhas com `password_hash()` (bcrypt); o banco nunca guarda a senha.
- Sessão por token aleatório em cookie `HttpOnly` + `SameSite=Lax`; o banco guarda só o hash SHA-256 do token, com expiração (8 h, em `config.php`).
- Token CSRF exigido em todo POST, PUT e DELETE de quem está logado (cabeçalho `X-CSRF-Token`, enviado por `js/api.js`).
- Perfil `ADMINISTRADOR` conferido em toda rota `/admin/*` (tentativas negadas vão para a auditoria).
- Consultas preparadas com PDO (sem SQL montado com texto do usuário).
- Toda criação, edição, exclusão, login e falha de login fica em `registro_auditoria`.
- `config/`, `src/`, `database/` e `api/controllers/` bloqueados para acesso direto pelo navegador (`.htaccess`).
