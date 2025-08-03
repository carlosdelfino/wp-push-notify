<?php
require_once __DIR__ . '/vendor/autoload.php';
use Minishlink\WebPush\VAPID;

$keys = VAPID::createVapidKeys();
echo "Public key:  {$keys['publicKey']}\n";
echo "Private key: {$keys['privateKey']}\n";