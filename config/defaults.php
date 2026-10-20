<?php

$override = __DIR__.'/conf.php';
if (is_file($override)) {
    require $override;
}
$defaults = [
    'ENVIRONMENT' => getenv('SIMPLEMAPPR_ENV') ?: 'development',
    'ROOT' => dirname(__DIR__),
    'MAPPR_URL' => rtrim(getenv('SIMPLEMAPPR_URL') ?: '', '/'),
    'MAPPR_MAPS_URL' => '/public/tmp/',
    'MAPPR_UPLOAD_DIRECTORY' => dirname(__DIR__).'/public/uploads',
    'NUMTEXTAREA' => 3,
    'MAXNUMTEXTAREA' => 10,
    'GOOGLE_ANALYTICS' => '',
    'RPX_KEY' => '',
    'CLOUDFLARE_KEY' => '',
    'CLOUDFLARE_DOMAIN' => '',
    'CLOUDFLARE_EMAIL' => '',
    'COOKIE_TIMEOUT' => time() + 1209600,
    'MAPPR_PERSISTENCE' => false,
];
foreach ($defaults as $name => $value) {
    defined($name) || define($name, $value);
}
