<?php
$root=dirname(__DIR__); $path=$root.'/.env';
if (!is_file($path)) copy($root.'/.env.example',$path);
$text=file_get_contents($path); $changed=0;
foreach (['APP_KEY'=>'base64:'.base64_encode(random_bytes(32)),'DB_PASSWORD'=>bin2hex(random_bytes(32)),'POSTGRES_ADMIN_PASSWORD'=>bin2hex(random_bytes(32)),'BILLING_WEBHOOK_SECRET'=>bin2hex(random_bytes(32))] as $name=>$value) {
    $pattern='/^'.preg_quote($name,'/').'=[ \t]*$/m';
    if (preg_match($pattern,$text)) { $text=preg_replace($pattern,$name.'='.$value,$text); $changed++; }
}
file_put_contents($path,$text,LOCK_EX); chmod($path,0600);
echo 'Prepared .env; generated '.$changed.' missing application secrets. Existing values were preserved. Configure domain, SMTP and billing before launch.'.PHP_EOL;
