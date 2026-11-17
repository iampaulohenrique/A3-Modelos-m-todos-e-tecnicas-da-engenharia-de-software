<?php
// GET /incidents (listar, com filtro) e POST /incidents (criar)
require __DIR__ . '/../../src/bootstrap.php';
match (Request::exigirMetodo('GET', 'POST')) {
    'GET'  => OcorrenciaController::listar(),
    'POST' => OcorrenciaController::criar(),
};
