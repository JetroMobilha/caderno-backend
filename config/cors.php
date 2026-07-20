<?php

/**
 * 🌐 CONFIGURAÇÃO DE CORS PARA FLUTTER WEB (Laravel)
 * Localização: config/cors.php
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'broadcasting/auth', 'storage/*'],

    'allowed_methods' => ['*'],

    // 🚀 ADICIONA O TEU DOMÍNIO OU IP ONDE O FLUTTER WEB ESTÁ HOSPEDADO
    // Se estiveres em desenvolvimento, podes usar '*' para testes, mas
    // especifica o IP em produção para segurança.
    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true, // 🔐 IMPORTANTE para o Sanctum/Autenticação

];