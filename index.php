<?php
// Raiz do site no Apache. Normalmente o .htaccess já entrega a página inicial em "/";
// este arquivo só é usado se o mod_rewrite estiver desligado. Entrega a página (sem redirecionar,
// para não criar loop com o redirecionamento /public/home.html → /).
header('Content-Type: text/html; charset=utf-8');
readfile(__DIR__ . '/public/home.html');
