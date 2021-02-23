<?php
if(!function_exists('hash_equals')) {
  function hash_equals($str1, $str2) {
    if(strlen($str1) != strlen($str2)) {
      return false;
    } else {
      $res = $str1 ^ $str2;
      $ret = 0;
      for($i = strlen($res) - 1; $i >= 0; $i--) $ret |= ord($res[$i]);
      return !$ret;
    }
  }
}

// We need to work with 5.3 workaround option change
if (!defined('OPENSSL_RAW_DATA'))
 define('OPENSSL_RAW_DATA', 1);

/**
 * AES Encryption/Decryption helper class
 *
 * @package Utility\AES
 */
class AES
{
private $key;
private $hmac_key;

public function __construct($key, $hmac_key)
{
$this->key=$key;
$this->hmac_key=$hmac_key;
}

/**
 * check_setup()
 *
 * Check that keys are set and correct size.
 *
 * Throws an Exception if something is wrong, otherwise does nothing.
 */
private function check_setup()
{
if (empty($this->key))
	throw new Exception("Encryption key is not set");
if (strlen($this->key)!==32)
	throw new Exception("Encryption key is wrong size");
if (empty($this->hmac_key))
	throw new Exception("HMAC key is not set");
}

/**
 * decrypt()
 *
 * Decrypts given $token. Token must be base64 encoded.
 */
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

/**
 * encrypt()
 *
 * Encrypts given $token, returns base64 encoded.
 */
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
