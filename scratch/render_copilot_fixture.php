<?php
require __DIR__ . '/copilot_fixture.php';
require dirname(__DIR__) . '/hooks.php';
require dirname(__DIR__) . '/controllers/AdminController.php';
$_GET['subtab'] = $argv[1] ?? 'console';
$reflection = new ReflectionClass(\Sahdev\Controllers\AdminController::class);
$controller = $reflection->newInstanceWithoutConstructor();
$property = $reflection->getProperty('moduleVars');
$property->setAccessible(true);
$property->setValue($controller, ['modulelink' => 'addonmodules.php?module=sahdev']);
echo '<!doctype html><html><head><meta charset="utf-8"></head><body>';
if ($_GET['subtab'] !== 'drawer') echo $controller->admin_copilot();
echo sahdev_render_admin_copilot_drawer();
echo '</body></html>';
