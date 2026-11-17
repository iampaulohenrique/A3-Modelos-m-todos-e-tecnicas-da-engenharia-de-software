-- =========================================================
-- CAMADA EXTRA (opcional): gatilhos que validam até INSERT/UPDATE feitos direto no banco,
-- sem passar pelo procedimento. Exigem privilégio para criar gatilhos (no XAMPP funciona;
-- em bancos gerenciados com log binário pode ser recusado). O importar.php tenta instalar
-- e, se o servidor não permitir, segue sem eles: o procedimento continua validando tudo
-- o que a aplicação grava.
-- =========================================================

DROP TRIGGER IF EXISTS trg_usuario_bu;
DROP TRIGGER IF EXISTS trg_ocorrencia_bi;
DROP TRIGGER IF EXISTS trg_ocorrencia_bu;

DELIMITER $$

-- CPF não pode ser trocado depois de definido
CREATE TRIGGER trg_usuario_bu BEFORE UPDATE ON usuario FOR EACH ROW
BEGIN
  SET NEW.email = LOWER(TRIM(NEW.email));
  IF OLD.cpf IS NOT NULL AND NOT (NEW.cpf <=> OLD.cpf) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CPF_IMUTAVEL';
  END IF;
END$$

-- Qualquer INSERT em ocorrencia: conta validada, Brasil, limite e repetição
CREATE TRIGGER trg_ocorrencia_bi BEFORE INSERT ON ocorrencia FOR EACH ROW
BEGIN
  SET NEW.data_hora = NOW();
  IF IFNULL((SELECT perfil_completo FROM vw_usuario_validado WHERE id = NEW.usuario_id), 0) = 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PERFIL_INCOMPLETO';
  END IF;
  IF CHAR_LENGTH(TRIM(NEW.endereco)) < 3 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ENDERECO_INVALIDO';
  END IF;
  IF NEW.latitude NOT BETWEEN -34.0 AND 5.5 OR NEW.longitude NOT BETWEEN -74.5 AND -28.5 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'FORA_DO_BRASIL';
  END IF;
  IF (SELECT COUNT(*) FROM ocorrencia
       WHERE usuario_id = NEW.usuario_id AND data_hora > NOW() - INTERVAL 1 HOUR) >= 10 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LIMITE_OCORRENCIAS';
  END IF;
  IF EXISTS (SELECT 1 FROM ocorrencia
              WHERE usuario_id = NEW.usuario_id AND categoria_id = NEW.categoria_id
                AND data_hora > NOW() - INTERVAL 30 MINUTE
                AND ABS(latitude - NEW.latitude) < 0.001 AND ABS(longitude - NEW.longitude) < 0.001) THEN
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

DELIMITER ;
