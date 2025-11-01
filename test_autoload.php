<?php
require __DIR__ . '/vendor/autoload.php';
echo "autoload loaded\n";
echo "Router file exists? ";
echo file_exists(__DIR__ . '/src/Router/Router.php') ? "YES\n" : "NO\n";
echo "Listing src/Router:\n";
foreach (glob(__DIR__ . '/src/Router/*') as $f) {
    echo " - $f\n";
}
echo "class_exists('App\\\\Router\\\\Router'): ";
var_export(class_exists('App\\Router\\Router'));
echo PHP_EOL;
