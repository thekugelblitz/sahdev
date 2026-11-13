<?php
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if (in_array($path,['/app.css','/app.js','/site.css','/site.js','/favicon.svg'],true)) return false;
require __DIR__.'/../public/index.php';
