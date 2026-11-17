<?php
// Raiz do site (Apache): leva para a página inicial.
// Endereço relativo: funciona no domínio (https://site/) e em subpasta (http://localhost/a3/).
header('Location: public/home.html', true, 302);
exit;
