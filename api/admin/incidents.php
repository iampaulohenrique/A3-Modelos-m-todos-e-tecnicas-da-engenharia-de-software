<?php
// PUT /admin/incidents/{id} e DELETE /admin/incidents/{id}  →  aqui: ?id={id}
require __DIR__ . '/../../src/bootstrap.php';
$id = Request::idDaQuery();
match (Request::exigirMetodo('PUT', 'DELETE')) {
    'PUT'    => AdminController::editarOcorrencia($id),
    'DELETE' => AdminController::excluirOcorrencia($id),
};
