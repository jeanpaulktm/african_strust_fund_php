<?php
declare(strict_types=1);

if (!defined('BASE_URL')) {
    $script = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $base = preg_replace('#/(auth|client|admin|caissier|includes|config)$#', '', $script);
    define('BASE_URL', rtrim($base ?? '', '/'));
}

/* Helpers chemins */
function asset(string $path): string {
    return BASE_URL . '/assets/' . ltrim($path, '/');
}
function url(string $path): string {
    return BASE_URL . '/' . ltrim($path, '/');
}

/* Raccourcis d'affichage (à utiliser DANS <head> et avant </body>) */
function css(string $file = 'css/style.css'): void {
    echo '<link rel="stylesheet" href="' . asset($file) . '">' . PHP_EOL;
}
function js(string $file = 'js/main.js'): void {
    echo '<script src="' . asset($file) . '"></script>' . PHP_EOL;
}
function logo(string $class = 'logo'): void {
    echo '<img src="' . asset('img/logo.png') . '" alt="AFRICAN TRUST FUND" class="' . $class . '">';
}