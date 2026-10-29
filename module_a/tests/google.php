<?php
declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
require __DIR__.'/../src/http.php';
require __DIR__.'/../src/auth.php';
require __DIR__.'/../src/google.php';
$checks=0;
function check(bool $value,string $label): void {global $checks;if (!$value) throw new RuntimeException($label);$checks++;}
function rejected(callable $action,string $label): void {global $checks;try {$action();}catch(ApiError){$checks++;return;}throw new RuntimeException($label);}
$key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
$public=openssl_pkey_get_details($key)['key'];
$encode=fn($value)=>rtrim(strtr(base64_encode($value),'+/','-_'),'=');
$make=function(array $claims,string $alg='RS256') use ($key,$encode): string {
    $head=$encode(json_encode(['alg'=>$alg,'kid'=>'test-key']));$body=$encode(json_encode($claims));
    openssl_sign($head.'.'.$body,$signature,$key,OPENSSL_ALGO_SHA256);
    return $head.'.'.$body.'.'.$encode($signature);
};
$claims=['iss'=>'https://accounts.google.com','aud'=>getenv('GOOGLE_CLIENT_ID'),'sub'=>'123456789','email'=>'test@example.com','email_verified'=>true,'nonce'=>'expected-nonce','exp'=>time()+3600,'iat'=>time()];
$valid=$make($claims);
check(googleClaims($valid,'expected-nonce',['test-key'=>$public])['sub']==='123456789','Корректный токен');
foreach ([['exp'=>time()-1],['aud'=>'other-client'],['iss'=>'https://attacker.example'],['nonce'=>'wrong-nonce'],['email_verified'=>false],['sub'=>''],['iat'=>time()+3600],['azp'=>'other-client']] as $change) {
    rejected(fn()=>googleClaims($make(array_merge($claims,$change)),'expected-nonce',['test-key'=>$public]),'Некорректные claims приняты');
}
rejected(fn()=>googleClaims($make($claims,'none'),'expected-nonce',['test-key'=>$public]),'Принят alg=none');
rejected(fn()=>googleClaims($valid,'expected-nonce',[]),'Принят неизвестный ключ');
$other=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
rejected(fn()=>googleClaims($valid,'expected-nonce',['test-key'=>openssl_pkey_get_details($other)['key']]),'Принята поддельная подпись');
rejected(fn()=>googleClaims('invalid-token','expected-nonce',[]),'Принят некорректный JWT');
echo "Google: $checks проверок подписи, срока, nonce, issuer и audience пройдено.\n";
