-- =========================================================
-- Sistema de Monitoramento de Ocorrências Ambientais  ·  versão 3
-- Funciona em XAMPP, hospedagem compartilhada e bancos gerenciados (Discloud):
-- usa só tabelas, CHECK, VIEW e PROCEDURE, que não exigem privilégio SUPER.
-- Os gatilhos (camada extra) ficam em database/gatilhos_extra.sql.
--
-- Jeito mais fácil (instala tudo e tenta os gatilhos):  php database/importar.php
-- Pelo phpMyAdmin: importe este arquivo e depois database/gatilhos_extra.sql
-- Banco já criado numa versão anterior:  php database/importar.php --arquivo=database/migracao_v3.sql
-- Depois crie o administrador:  php database/criar_admin.php
-- =========================================================

SET NAMES utf8mb4;

CREATE DATABASE IF NOT EXISTS ocorrencias_ambientais
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE ocorrencias_ambientais;

-- Usuario (perfil = enum Perfil do diagrama). CPF e endereço = conta validada.
CREATE TABLE IF NOT EXISTS usuario (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome        VARCHAR(120) NOT NULL,
  email       VARCHAR(190) NOT NULL UNIQUE,
  senha_hash  VARCHAR(255) NOT NULL,
  perfil      ENUM('USUARIO_COMUM', 'ADMINISTRADOR') NOT NULL DEFAULT 'USUARIO_COMUM',
  cpf         CHAR(11) NULL UNIQUE,
  cep         CHAR(8) NULL,
  logradouro  VARCHAR(150) NULL,
  numero      VARCHAR(10) NULL,
  complemento VARCHAR(60) NULL,
  bairro      VARCHAR(80) NULL,
  cidade      VARCHAR(80) NULL,
  uf          CHAR(2) NULL,
  criado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT ck_usuario_nome  CHECK (CHAR_LENGTH(TRIM(nome)) >= 3),
  CONSTRAINT ck_usuario_email CHECK (email LIKE '%_@_%._%'),
  -- CPF: 11 dígitos, não repetidos e com os dois dígitos verificadores corretos
  CONSTRAINT ck_usuario_cpf   CHECK (cpf IS NULL OR (
      cpf REGEXP '^[0-9]{11}$'
      AND cpf NOT IN ('00000000000', '11111111111', '22222222222', '33333333333', '44444444444', '55555555555', '66666666666', '77777777777', '88888888888', '99999999999')
      AND MOD(MOD((CAST(SUBSTRING(cpf,1,1) AS UNSIGNED)*10 + CAST(SUBSTRING(cpf,2,1) AS UNSIGNED)*9 + CAST(SUBSTRING(cpf,3,1) AS UNSIGNED)*8 + CAST(SUBSTRING(cpf,4,1) AS UNSIGNED)*7 + CAST(SUBSTRING(cpf,5,1) AS UNSIGNED)*6 + CAST(SUBSTRING(cpf,6,1) AS UNSIGNED)*5 + CAST(SUBSTRING(cpf,7,1) AS UNSIGNED)*4 + CAST(SUBSTRING(cpf,8,1) AS UNSIGNED)*3 + CAST(SUBSTRING(cpf,9,1) AS UNSIGNED)*2) * 10, 11), 10) = CAST(SUBSTRING(cpf,10,1) AS UNSIGNED)
      AND MOD(MOD((CAST(SUBSTRING(cpf,1,1) AS UNSIGNED)*11 + CAST(SUBSTRING(cpf,2,1) AS UNSIGNED)*10 + CAST(SUBSTRING(cpf,3,1) AS UNSIGNED)*9 + CAST(SUBSTRING(cpf,4,1) AS UNSIGNED)*8 + CAST(SUBSTRING(cpf,5,1) AS UNSIGNED)*7 + CAST(SUBSTRING(cpf,6,1) AS UNSIGNED)*6 + CAST(SUBSTRING(cpf,7,1) AS UNSIGNED)*5 + CAST(SUBSTRING(cpf,8,1) AS UNSIGNED)*4 + CAST(SUBSTRING(cpf,9,1) AS UNSIGNED)*3 + CAST(SUBSTRING(cpf,10,1) AS UNSIGNED)*2) * 10, 11), 10) = CAST(SUBSTRING(cpf,11,1) AS UNSIGNED)
    )),
  CONSTRAINT ck_usuario_cep   CHECK (cep IS NULL OR cep REGEXP '^[0-9]{8}$'),
  CONSTRAINT ck_usuario_uf    CHECK (uf IS NULL OR uf IN ('AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'))
) ENGINE=InnoDB;

-- Sessao: o token vai num cookie HttpOnly; aqui fica só o hash dele (SHA-256)
CREATE TABLE IF NOT EXISTS sessao (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id  INT UNSIGNED NOT NULL,
  token_hash  CHAR(64) NOT NULL UNIQUE,
  csrf_token  CHAR(64) NOT NULL,
  ip          VARCHAR(45) NULL,
  criada_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ultimo_uso  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expira_em   DATETIME NOT NULL,
  CONSTRAINT fk_sessao_usuario FOREIGN KEY (usuario_id) REFERENCES usuario(id) ON DELETE CASCADE,
  INDEX idx_sessao_expira (expira_em)
) ENGINE=InnoDB;

-- Tentativas de login (para bloquear força bruta). O e-mail fica em hash.
CREATE TABLE IF NOT EXISTS tentativa_login (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email_hash  CHAR(64) NOT NULL,
  ip          VARCHAR(45) NOT NULL,
  sucesso     TINYINT(1) NOT NULL DEFAULT 0,
  data_hora   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_tentativa_email (email_hash, data_hora),
  INDEX idx_tentativa_ip (ip, data_hora)
) ENGINE=InnoDB;

-- Categoria: alagamento, erosão, queda de talude (com ícone e cor do pino)
CREATE TABLE IF NOT EXISTS categoria (
  id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug   VARCHAR(30) NOT NULL UNIQUE,
  nome   VARCHAR(60) NOT NULL,
  icone  VARCHAR(40) NOT NULL,
  cor    CHAR(7) NOT NULL
) ENGINE=InnoDB;

-- Ocorrencia
CREATE TABLE IF NOT EXISTS ocorrencia (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id    INT UNSIGNED NOT NULL,
  categoria_id  INT UNSIGNED NOT NULL,
  descricao     VARCHAR(500) NOT NULL DEFAULT '',
  endereco      VARCHAR(255) NOT NULL,
  latitude      DECIMAL(10, 7) NOT NULL,
  longitude     DECIMAL(10, 7) NOT NULL,
  data_hora     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ocorrencia_usuario FOREIGN KEY (usuario_id) REFERENCES usuario(id) ON DELETE CASCADE,
  CONSTRAINT fk_ocorrencia_categoria FOREIGN KEY (categoria_id) REFERENCES categoria(id),
  CONSTRAINT ck_ocorrencia_lat CHECK (latitude BETWEEN -90 AND 90),
  CONSTRAINT ck_ocorrencia_lng CHECK (longitude BETWEEN -180 AND 180),
  INDEX idx_ocorrencia_data (data_hora),
  INDEX idx_ocorrencia_categoria (categoria_id),
  INDEX idx_ocorrencia_usuario_data (usuario_id, data_hora)
) ENGINE=InnoDB;

-- RegistroAuditoria: login, criação, edição, exclusão e acessos negados.
CREATE TABLE IF NOT EXISTS registro_auditoria (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id      INT UNSIGNED NULL,
  ocorrencia_id   INT UNSIGNED NULL,
  acao            VARCHAR(40) NOT NULL,
  nivel           ENUM('INFO', 'WARN', 'ERROR') NOT NULL DEFAULT 'INFO',
  campo_alterado  VARCHAR(60) NULL,
  valor_anterior  TEXT NULL,
  valor_novo      TEXT NULL,
  data_hora       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_auditoria_usuario FOREIGN KEY (usuario_id) REFERENCES usuario(id) ON DELETE SET NULL,
  CONSTRAINT fk_auditoria_ocorrencia FOREIGN KEY (ocorrencia_id) REFERENCES ocorrencia(id) ON DELETE SET NULL,
  INDEX idx_auditoria_data (data_hora)
) ENGINE=InnoDB;

-- ---------- Dados iniciais ----------
INSERT IGNORE INTO categoria (slug, nome, icone, cor) VALUES
  ('alagamento', 'Alagamento', 'bi-water', '#0b6fb0'),
  ('erosao', 'Erosão', 'bi-cone-striped', '#a35a00'),
  ('talude', 'Queda de talude', 'bi-exclamation-octagon', '#c42b2b');
-- Sem administrador padrão: crie o seu com  php database/criar_admin.php


-- ---------------------------------------------------------
-- Regras de validação no banco (VIEW + PROCEDURES)
-- Não usam funções nem gatilhos: funcionam sem privilégio SUPER.
-- ---------------------------------------------------------

-- Conta "validada": nome, CPF (já validado pelo CHECK) e endereço completo
CREATE OR REPLACE VIEW vw_usuario_validado AS
SELECT u.id,
       (CHAR_LENGTH(TRIM(u.nome)) >= 3
        AND u.cpf IS NOT NULL
        AND u.cep IS NOT NULL
        AND CHAR_LENGTH(TRIM(IFNULL(u.logradouro, ''))) >= 3
        AND CHAR_LENGTH(TRIM(IFNULL(u.numero, ''))) >= 1
        AND CHAR_LENGTH(TRIM(IFNULL(u.bairro, ''))) >= 2
        AND CHAR_LENGTH(TRIM(IFNULL(u.cidade, ''))) >= 2
        AND u.uf IS NOT NULL) AS perfil_completo
  FROM usuario u;

DROP PROCEDURE IF EXISTS sp_registrar_ocorrencia;
DROP PROCEDURE IF EXISTS sp_atualizar_perfil;

DELIMITER $$

-- Único caminho da aplicação para criar ocorrência. O banco confere:
-- sessão válida (pelo hash do token), conta validada, tipo, endereço, ponto no Brasil,
-- limite de 10 por hora e repetição (mesmo tipo a menos de ~100 m em 30 min).
CREATE PROCEDURE sp_registrar_ocorrencia(
  IN  p_token_hash CHAR(64),
  IN  p_categoria  VARCHAR(30),
  IN  p_descricao  VARCHAR(500),
  IN  p_endereco   VARCHAR(255),
  IN  p_lat        DECIMAL(10, 7),
  IN  p_lng        DECIMAL(10, 7),
  OUT p_id         INT UNSIGNED
)
MODIFIES SQL DATA
BEGIN
  DECLARE v_usuario INT UNSIGNED DEFAULT NULL;
  DECLARE v_categoria INT UNSIGNED DEFAULT NULL;
  DECLARE v_completo TINYINT DEFAULT 0;
  DECLARE CONTINUE HANDLER FOR NOT FOUND BEGIN END;

  SELECT usuario_id INTO v_usuario FROM sessao
   WHERE token_hash = p_token_hash AND expira_em > NOW() LIMIT 1;
  IF v_usuario IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SESSAO_INVALIDA';
  END IF;

  SELECT perfil_completo INTO v_completo FROM vw_usuario_validado WHERE id = v_usuario;
  IF IFNULL(v_completo, 0) = 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PERFIL_INCOMPLETO';
  END IF;

  SELECT id INTO v_categoria FROM categoria WHERE slug = p_categoria LIMIT 1;
  IF v_categoria IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CATEGORIA_INVALIDA';
  END IF;

  IF p_endereco IS NULL OR CHAR_LENGTH(TRIM(p_endereco)) < 3 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ENDERECO_INVALIDO';
  END IF;

  -- caixa aproximada do território brasileiro
  IF p_lat IS NULL OR p_lng IS NULL OR p_lat NOT BETWEEN -34.0 AND 5.5 OR p_lng NOT BETWEEN -74.5 AND -28.5 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'FORA_DO_BRASIL';
  END IF;

  IF (SELECT COUNT(*) FROM ocorrencia
       WHERE usuario_id = v_usuario AND data_hora > NOW() - INTERVAL 1 HOUR) >= 10 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LIMITE_OCORRENCIAS';
  END IF;

  IF EXISTS (SELECT 1 FROM ocorrencia
              WHERE usuario_id = v_usuario AND categoria_id = v_categoria
                AND data_hora > NOW() - INTERVAL 30 MINUTE
                AND ABS(latitude - p_lat) < 0.001 AND ABS(longitude - p_lng) < 0.001) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'OCORRENCIA_DUPLICADA';
  END IF;

  INSERT INTO ocorrencia (usuario_id, categoria_id, descricao, endereco, latitude, longitude, data_hora)
  VALUES (v_usuario, v_categoria, TRIM(IFNULL(p_descricao, '')), TRIM(p_endereco), p_lat, p_lng, NOW());
  SET p_id = LAST_INSERT_ID();

  INSERT INTO registro_auditoria (usuario_id, ocorrencia_id, acao, nivel, campo_alterado, valor_novo)
  VALUES (v_usuario, p_id, 'CRIAR_OCORRENCIA', 'INFO', 'categoria', p_categoria);
END$$

-- Atualiza nome, CPF e endereço da conta da sessão. O CPF só pode ser definido uma vez;
-- formato e dígitos do CPF, CEP e UF são conferidos pelos CHECK da tabela.
CREATE PROCEDURE sp_atualizar_perfil(
  IN p_token_hash  CHAR(64),
  IN p_nome        VARCHAR(120),
  IN p_cpf         CHAR(11),
  IN p_cep         CHAR(8),
  IN p_logradouro  VARCHAR(150),
  IN p_numero      VARCHAR(10),
  IN p_complemento VARCHAR(60),
  IN p_bairro      VARCHAR(80),
  IN p_cidade      VARCHAR(80),
  IN p_uf          CHAR(2)
)
MODIFIES SQL DATA
BEGIN
  DECLARE v_usuario INT UNSIGNED DEFAULT NULL;
  DECLARE v_cpf_atual CHAR(11) DEFAULT NULL;
  DECLARE CONTINUE HANDLER FOR NOT FOUND BEGIN END;

  SELECT usuario_id INTO v_usuario FROM sessao
   WHERE token_hash = p_token_hash AND expira_em > NOW() LIMIT 1;
  IF v_usuario IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SESSAO_INVALIDA';
  END IF;

  SELECT cpf INTO v_cpf_atual FROM usuario WHERE id = v_usuario;
  IF v_cpf_atual IS NOT NULL AND NOT (p_cpf <=> v_cpf_atual) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CPF_IMUTAVEL';
  END IF;

  UPDATE usuario
     SET nome = TRIM(p_nome), cpf = p_cpf, cep = p_cep, logradouro = TRIM(p_logradouro),
         numero = TRIM(p_numero), complemento = NULLIF(TRIM(p_complemento), ''),
         bairro = TRIM(p_bairro), cidade = TRIM(p_cidade), uf = UPPER(p_uf)
   WHERE id = v_usuario;
END$$

DELIMITER ;
