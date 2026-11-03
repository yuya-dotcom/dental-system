
<?php
echo "PHP Version: " . PHP_VERSION . "<br>";
echo "PHP SAPI: " . php_sapi_name() . "<br>";
echo "Loaded php.ini: " . php_ini_loaded_file() . "<br>";
echo "Extension directory: " . ini_get('extension_dir') . "<br>";
echo "cURL enabled: " . (function_exists('curl_init') ? 'YES' : 'NO') . "<br>";
?>
