<?php

// Preload do opcache em produção: as classes do container já entram na
// memória compartilhada no boot do FPM, em vez de serem carregadas na
// primeira requisição de cada worker.
//
// O nome do arquivo deriva da classe do Kernel — aqui `Lugar\Kernel`, e não
// o `App\Kernel` que o recipe do Symfony assume por padrão.
if (file_exists(dirname(__DIR__).'/var/cache/prod/Lugar_KernelProdContainer.preload.php')) {
    require dirname(__DIR__).'/var/cache/prod/Lugar_KernelProdContainer.preload.php';
}
