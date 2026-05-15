<?php

use Valet\Drivers\BasicValetDriver;

class LocalValetDriver extends BasicValetDriver
{
    public function serves(string $sitePath, string $siteName, string $uri): bool
    {
        return is_file($sitePath . '/config.php')
            && is_file($sitePath . '/index.php')
            && is_dir($sitePath . '/catalog')
            && is_dir($sitePath . '/system');
    }

    public function beforeLoading(string $sitePath, string $siteName, string $uri): void
    {
        parent::beforeLoading($sitePath, $siteName, $uri);

        $path = trim($uri, '/');

        if ($path !== ''
            && $path !== 'index.php'
            && !str_starts_with($path, 'urednik/')
            && !isset($_GET['_route_'])
            && !isset($_GET['route'])
        ) {
            $_GET['_route_'] = $path;
            $_REQUEST['_route_'] = $path;
        }
    }
}
