<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$lock = fopen(sys_get_temp_dir().'/shopbonus-'.hash('sha256', __DIR__).'.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}
require dirname(__DIR__, 3).'/config/config.inc.php';
$module = Module::getInstanceByName('shopbonus');
if ($module && $module->active) {
    $module->deliverQueue();
}
