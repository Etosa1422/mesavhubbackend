<?php
// Simple cache/route clearer - DELETE THIS FILE after use
define('LARAVEL_START', microtime(true));
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$results = [];
try { Artisan::call('route:clear'); $results[] = 'route:clear OK'; } catch(Exception $e) { $results[] = 'route:clear FAILED: '.$e->getMessage(); }
try { Artisan::call('cache:clear'); $results[] = 'cache:clear OK'; } catch(Exception $e) { $results[] = 'cache:clear FAILED: '.$e->getMessage(); }
try { Artisan::call('config:clear'); $results[] = 'config:clear OK'; } catch(Exception $e) { $results[] = 'config:clear FAILED: '.$e->getMessage(); }

foreach($results as $r) echo $r . '<br>';
echo 'Done. DELETE THIS FILE NOW.';
