<?php
// Bundled copy of wulopay/wulopay-php (MIT, https://github.com/WuloPay/wulopay-php). Loaded only when no other
// copy of the library is already available (e.g. through Composer).
if (!class_exists('WuloPay\\Client', false)) {
    spl_autoload_register(static function ($class) {
        if (strpos($class, 'WuloPay\\') !== 0) {
            return;
        }
        $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, 8)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    });
}
