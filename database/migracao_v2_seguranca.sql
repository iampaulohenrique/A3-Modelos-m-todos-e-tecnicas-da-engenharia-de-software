-- =========================================================
-- Migração v1 -> v2 (segurança). Use se você JÁ importou o schema.sql antigo.
-- Mantém usuários e ocorrências; acrescenta CPF/endereço, tentativas de login,
-- validações no banco e o procedimento de registro de ocorrência.
-- Sintaxe MariaDB (a do XAMPP).
-- =========================================================

SET NAMES utf8mb4;
USE ocorrencias_ambientais;

ALTER TABLE usuario
  ADD COLUMN IF NOT EXISTS cpf CHAR(11) NULL UNIQUE AFTER perfil,
  ADD COLUMN IF NOT EXISTS cep CHAR(8) NULL AFTER cpf,
  ADD COLUMN IF NOT EXISTS logradouro VARCHAR(150) NULL AFTER cep,
  ADD COLUMN IF NOT EXISTS numero VARCHAR(10) NULL AFTER logradouro,
  ADD COLUMN IF NOT EXISTS complemento VARCHAR(60) NULL AFTER numero,
  ADD COLUMN IF NOT EXISTS bairro VARCHAR(80) NULL AFTER complemento,
  ADD COLUMN IF NOT EXISTS cidade VARCHAR(80) NULL AFTER bairro,
  ADD COLUMN IF NOT EXISTS uf CHAR(2) NULL AFTER cidade;

ALTER TABLE usuario
  ADD CONSTRAINT ck_usuario_nome  CHECK (CHAR_LENGTH(TRIM(nome)) >= 3),
  ADD CONSTRAINT ck_usuario_email CHECK (email LIKE '%_@_%._%'),
  ADD CONSTRAINT ck_usuario_cpf   CHECK (cpf IS NULL OR cpf REGEXP '^[0-9]{11}$'),
  ADD CONSTRAINT ck_usuario_cep   CHECK (cep IS NULL OR cep REGEXP '^[0-9]{8}$'),
  ADD CONSTRAINT ck_usuario_uf    CHECK (uf IS NULL OR uf IN ('AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'));

ALTER TABLE sessao
  ADD COLUMN IF NOT EXISTS ip VARCHAR(45) NULL AFTER csrf_token,
  ADD COLUMN IF NOT EXISTS ultimo_uso DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER criada_em;

ALTER TABLE ocorrencia
  ADD CONSTRAINT ck_ocorrencia_lat CHECK (latitude BETWEEN -90 AND 90),
  ADD CONSTRAINT ck_ocorrencia_lng CHECK (longitude BETWEEN -180 AND 180),
  ADD INDEX IF NOT EXISTS idx_ocorrencia_usuario_data (usuario_id, data_hora);

CREATE TABLE IF NOT EXISTS tentativa_login (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email_hash  CHAR(64) NOT NULL,
  ip          VARCHAR(45) NOT NULL,
  sucesso     TINYINT(1) NOT NULL DEFAULT 0,
  data_hora   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_tentativa_email (email_hash, data_hora),
  INDEX idx_tentativa_ip (ip, data_hora)
) ENGINE=InnoDB;

-- Encerra as sessões antigas (todo mundo entra de novo com as regras novas)
DELETE FROM sessao;

-- ---------------------------------------------------------
-- Regras de validação no banco (funções, gatilhos e procedimento)
-- O banco recusa sozinho qualquer dado inválido, mesmo que alguém
-- consiga escrever direto nas tabelas sem passar pelo PHP.
-- ---------------------------------------------------------

DROP FUNCTION IF EXISTS fn_cpf_valido;
DROP FUNCTION IF EXISTS fn_perfil_completo;
DROP TRIGGER IF EXISTS trg_usuario_bi;
DROP TRIGGER IF EXISTS trg_usuario_bu;
DROP TRIGGER IF EXISTS trg_ocorrencia_bi;
DROP TRIGGER IF EXISTS trg_ocorrencia_bu;
DROP PROCEDURE IF EXISTS sp_registrar_ocorrencia;

DELIMITER $$

-- CPF: 11 dígitos, não repetidos, com os dois dígitos verificadores corretos
CREATE FUNCTION fn_cpf_valido(p VARCHAR(11)) RETURNS TINYINT(1)
DETERMINISTIC NO SQL
BEGIN
  DECLARE i INT DEFAULT 1;
  DECLARE s INT DEFAULT 0;
  DECLARE d INT;
  IF p IS NULL OR p NOT REGEXP '^[0-9]{11}$' THEN RETURN 0; END IF;
  IF p = REPEAT(SUBSTRING(p, 1, 1), 11) THEN RETURN 0; END IF;

  WHILE i <= 9 DO
    SET s = s + CAST(SUBSTRING(p, i, 1) AS UNSIGNED) * (11 - i);
    SET i = i + 1;
  END WHILE;
  SET d = (s * 10) MOD 11;
  IF d = 10 THEN SET d = 0; END IF;
  IF d <> CAST(SUBSTRING(p, 10, 1) AS UNSIGNED) THEN RETURN 0; END IF;

  SET s = 0; SET i = 1;
  WHILE i <= 10 DO
    SET s = s + CAST(SUBSTRING(p, i, 1) AS UNSIGNED) * (12 - i);
    SET i = i + 1;
  END WHILE;
  SET d = (s * 10) MOD 11;
  IF d = 10 THEN SET d = 0; END IF;
  RETURN d = CAST(SUBSTRING(p, 11, 1) AS UNSIGNED);
END$$

-- Conta "validada": nome, CPF válido e endereço completo
CREATE FUNCTION fn_perfil_completo(p_usuario INT UNSIGNED) RETURNS TINYINT(1)
NOT DETERMINISTIC READS SQL DATA
BEGIN
  DECLARE ok TINYINT(1) DEFAULT 0;
  SELECT (CHAR_LENGTH(TRIM(nome)) >= 3
          AND fn_cpf_valido(cpf) = 1
          AND cep IS NOT NULL
          AND CHAR_LENGTH(TRIM(IFNULL(logradouro, ''))) >= 3
          AND CHAR_LENGTH(TRIM(IFNULL(numero, ''))) >= 1
          AND CHAR_LENGTH(TRIM(IFNULL(bairro, ''))) >= 2
          AND CHAR_LENGTH(TRIM(IFNULL(cidade, ''))) >= 2
          AND uf IS NOT NULL)
    INTO ok
    FROM usuario WHERE id = p_usuario;
  RETURN IFNULL(ok, 0);
END$$

-- Usuário: CPF válido ao gravar; CPF não pode ser trocado depois de definido
CREATE TRIGGER trg_usuario_bi BEFORE INSERT ON usuario FOR EACH ROW
BEGIN
  SET NEW.email = LOWER(TRIM(NEW.email));
  IF NEW.cpf IS NOT NULL AND fn_cpf_valido(NEW.cpf) = 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CPF_INVALIDO';
  END IF;
END$$

CREATE TRIGGER trg_usuario_bu BEFORE UPDATE ON usuario FOR EACH ROW
BEGIN
  SET NEW.email = LOWER(TRIM(NEW.email));
  IF OLD.cpf IS NOT NULL AND NOT (NEW.cpf <=> OLD.cpf) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CPF_IMUTAVEL';
  END IF;
  IF NEW.cpf IS NOT NULL AND fn_cpf_valido(NEW.cpf) = 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CPF_INVALIDO';
  END IF;
END$$

-- Ocorrência: só de conta validada, dentro do Brasil, sem repetição e sem enxurrada
CREATE TRIGGER trg_ocorrencia_bi BEFORE INSERT ON ocorrencia FOR EACH ROW
BEGIN
  -- a data é sempre a do servidor (ninguém registra "no passado")
  SET NEW.data_hora = NOW();

  IF fn_perfil_completo(NEW.usuario_id) = 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PERFIL_INCOMPLETO';
  END IF;

  IF CHAR_LENGTH(TRIM(NEW.endereco)) < 3 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ENDERECO_INVALIDO';
  END IF;

  -- caixa aproximada do território brasileiro
  IF NEW.latitude NOT BETWEEN -34.0 AND 5.5 OR NEW.longitude NOT BETWEEN -74.5 AND -28.5 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'FORA_DO_BRASIL';
  END IF;

  -- no máximo 10 alertas por conta a cada hora
  IF (SELECT COUNT(*) FROM ocorrencia
       WHERE usuario_id = NEW.usuario_id AND data_hora > NOW() - INTERVAL 1 HOUR) >= 10 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LIMITE_OCORRENCIAS';
  END IF;

  -- mesmo tipo, mesma conta, a menos de ~100 m, nos últimos 30 minutos = repetido
  IF EXISTS (SELECT 1 FROM ocorrencia
              WHERE usuario_id = NEW.usuario_id
                AND categoria_id = NEW.categoria_id
                AND data_hora > NOW() - INTERVAL 30 MINUTE
                AND ABS(latitude - NEW.latitude) < 0.001
                AND ABS(longitude - NEW.longitude) < 0.001) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'OCORRENCIA_DUPLICADA';
  END IF;
END$$

-- Edição (admin): local, dono e data não mudam
CREATE TRIGGER trg_ocorrencia_bu BEFORE UPDATE ON ocorrencia FOR EACH ROW
BEGIN
  IF NEW.usuario_id <> OLD.usuario_id OR NEW.latitude <> OLD.latitude
     OR NEW.longitude <> OLD.longitude OR NEW.data_hora <> OLD.data_hora THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CAMPO_IMUTAVEL';
  END IF;
END$$

-- Único caminho para criar ocorrência: o banco confere a sessão (hash do token),
-- a validade dela e, pelo gatilho, se a conta está validada.
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
SQL SECURITY DEFINER
BEGIN
  DECLARE v_usuario INT UNSIGNED DEFAULT NULL;
  DECLARE v_categoria INT UNSIGNED DEFAULT NULL;
  DECLARE CONTINUE HANDLER FOR NOT FOUND BEGIN END;

  SELECT usuario_id INTO v_usuario
    FROM sessao
   WHERE token_hash = p_token_hash AND expira_em > NOW()
   LIMIT 1;
  IF v_usuario IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SESSAO_INVALIDA';
  END IF;

  SELECT id INTO v_categoria FROM categoria WHERE slug = p_categoria LIMIT 1;
  IF v_categoria IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CATEGORIA_INVALIDA';
  END IF;

  INSERT INTO ocorrencia (usuario_id, categoria_id, descricao, endereco, latitude, longitude)
  VALUES (v_usuario, v_categoria, TRIM(IFNULL(p_descricao, '')), TRIM(p_endereco), p_lat, p_lng);
  SET p_id = LAST_INSERT_ID();

  INSERT INTO registro_auditoria (usuario_id, ocorrencia_id, acao, nivel, campo_alterado, valor_novo)
  VALUES (v_usuario, p_id, 'CRIAR_OCORRENCIA', 'INFO', 'categoria', p_categoria);
END$$

DELIMITER ;
