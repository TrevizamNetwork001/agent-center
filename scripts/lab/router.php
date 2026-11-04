<?php
// Lab PHP server: serve existing public assets, route all other requests through Laravel.
$root=getcwd().'/public';$path=realpath($root.(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)?:'/'));
if($path&&str_starts_with($path,$root.'/')&&is_file($path)&&!str_ends_with($path,'.php'))return false;
require $root.'/index.php';
