-- =========================================================
-- (Recomendado em produção, só onde você pode criar usuários: XAMPP, VPS)
-- Usuário do banco com o MÍNIMO de permissões.
-- Sem permissão de INSERT em `ocorrencia`: a única forma de criar um alerta
-- é o procedimento sp_registrar_ocorrencia, que valida sessão e conta.
-- Troque a senha abaixo e use este usuário em .env (DB_USER / DB_PASSWORD).
-- =========================================================
CREATE USER IF NOT EXISTS 'app_ocorrencias'@'localhost' IDENTIFIED BY 'TROQUE-POR-UMA-SENHA-FORTE';

GRANT SELECT ON ocorrencias_ambientais.* TO 'app_ocorrencias'@'localhost';
GRANT INSERT, UPDATE ON ocorrencias_ambientais.usuario TO 'app_ocorrencias'@'localhost';   -- UPDATE: troca de senha/admin
GRANT INSERT, UPDATE, DELETE ON ocorrencias_ambientais.sessao TO 'app_ocorrencias'@'localhost';
GRANT INSERT, DELETE ON ocorrencias_ambientais.tentativa_login TO 'app_ocorrencias'@'localhost';
GRANT INSERT ON ocorrencias_ambientais.registro_auditoria TO 'app_ocorrencias'@'localhost';
-- ocorrencia: só editar e excluir (admin). Criar = apenas pelo procedimento.
GRANT UPDATE, DELETE ON ocorrencias_ambientais.ocorrencia TO 'app_ocorrencias'@'localhost';
GRANT EXECUTE ON PROCEDURE ocorrencias_ambientais.sp_registrar_ocorrencia TO 'app_ocorrencias'@'localhost';
GRANT EXECUTE ON PROCEDURE ocorrencias_ambientais.sp_atualizar_perfil TO 'app_ocorrencias'@'localhost';
FLUSH PRIVILEGES;
