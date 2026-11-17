-- =========================================================
-- Migração para a versão 3 (compatível com bancos gerenciados, como a Discloud).
-- Use se o banco já tem a versão 2 (migracao_v2_seguranca.sql) ou a 1 já migrada.
-- Troca funções e gatilhos antigos por CHECK + VIEW + PROCEDURES, mantendo os dados.
--   php database/importar.php --arquivo=database/migracao_v3.sql
-- =========================================================

SET NAMES utf8mb4;

DROP TRIGGER IF EXISTS trg_usuario_bi;
DROP TRIGGER IF EXISTS trg_usuario_bu;
DROP TRIGGER IF EXISTS trg_ocorrencia_bi;
DROP TRIGGER IF EXISTS trg_ocorrencia_bu;
DROP FUNCTION IF EXISTS fn_perfil_completo;
DROP FUNCTION IF EXISTS fn_cpf_valido;

-- CPF com dígitos verificadores agora num CHECK (não precisa de função)
ALTER TABLE usuario DROP CONSTRAINT IF EXISTS ck_usuario_cpf;
ALTER TABLE usuario ADD CONSTRAINT ck_usuario_cpf CHECK (cpf IS NULL OR (
      cpf REGEXP '^[0-9]{11}$'
      AND cpf NOT IN ('00000000000', '11111111111', '22222222222', '33333333333', '44444444444', '55555555555', '66666666666', '77777777777', '88888888888', '99999999999')
      AND MOD(MOD((CAST(SUBSTRING(cpf,1,1) AS UNSIGNED)*10 + CAST(SUBSTRING(cpf,2,1) AS UNSIGNED)*9 + CAST(SUBSTRING(cpf,3,1) AS UNSIGNED)*8 + CAST(SUBSTRING(cpf,4,1) AS UNSIGNED)*7 + CAST(SUBSTRING(cpf,5,1) AS UNSIGNED)*6 + CAST(SUBSTRING(cpf,6,1) AS UNSIGNED)*5 + CAST(SUBSTRING(cpf,7,1) AS UNSIGNED)*4 + CAST(SUBSTRING(cpf,8,1) AS UNSIGNED)*3 + CAST(SUBSTRING(cpf,9,1) AS UNSIGNED)*2) * 10, 11), 10) = CAST(SUBSTRING(cpf,10,1) AS UNSIGNED)
      AND MOD(MOD((CAST(SUBSTRING(cpf,1,1) AS UNSIGNED)*11 + CAST(SUBSTRING(cpf,2,1) AS UNSIGNED)*10 + CAST(SUBSTRING(cpf,3,1) AS UNSIGNED)*9 + CAST(SUBSTRING(cpf,4,1) AS UNSIGNED)*8 + CAST(SUBSTRING(cpf,5,1) AS UNSIGNED)*7 + CAST(SUBSTRING(cpf,6,1) AS UNSIGNED)*6 + CAST(SUBSTRING(cpf,7,1) AS UNSIGNED)*5 + CAST(SUBSTRING(cpf,8,1) AS UNSIGNED)*4 + CAST(SUBSTRING(cpf,9,1) AS UNSIGNED)*3 + CAST(SUBSTRING(cpf,10,1) AS UNSIGNED)*2) * 10, 11), 10) = CAST(SUBSTRING(cpf,11,1) AS UNSIGNED)
    ));

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
