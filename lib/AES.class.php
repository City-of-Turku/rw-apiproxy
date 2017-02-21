<?php
class AES
{
private $key;
private $hmac_key;

public function __construct($key, $hmac_key)
{
$this->key=$key;
$this->hmac_key=$hmac_key;
}

private function check_setup()
{
if (strlen($this->key)!==32)
	throw new Exception("Encryption key is not set");
if (strlen($this->hmac_key)===0)
	throw new Exception("HMAC key is not set");
}

public function decrypt($token)
{
$this->check_setup();
$tmp=base64_decode($token);
$hash=substr($tmp, 0, 32);
$iv=substr($tmp, 32, 16);
$text=substr($tmp, 48);
$chash=hash_hmac('sha256', $iv.$text, $this->hmac_key, true);
if (!hash_equals($hash, $chash)) // XXX PHP 5.6->
        return false;

// $iv_size=openssl_cipher_iv_length('aes-256-cbc');
return openssl_decrypt($text, 'aes-256-cbc', $this->key, OPENSSL_RAW_DATA, $iv);
}

public function encrypt($token)
{
$this->check_setup();
$iv_size=openssl_cipher_iv_length('aes-256-cbc');
$iv=openssl_random_pseudo_bytes($iv_size);
$tmp=openssl_encrypt($token, 'aes-256-cbc', $this->key, OPENSSL_RAW_DATA, $iv);
$hash=hash_hmac('sha256', $iv.$tmp, $this->hmac_key, true);
return base64_encode($hash.$iv.$tmp);
}

}
