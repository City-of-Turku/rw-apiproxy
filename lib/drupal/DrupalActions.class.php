<?php

require_once('DrupalServiceAPIClient.class.php');

class DrupalActions extends BackendActionsInterface
{
private $d;
private $uid;
private $session_id;
private $session_name;
private $session_token;

private $hmac_key;
private $decrypt_key;

public function __construct($url)
{
$this->d=new DrupalServiceAPIClient($url);
$this->d->set_auth_type(AUTH_SESSION);
$this->hmac_key='xxx';
$this->decrypt_key='yyy';
}

public function set_auth($username, $password)
{
return $this->d->set_auth($username, $password);
}

private function decrypt_token($token)
{
$tmp=base64_decode($token);
$hash=substr($tmp, 0, 32);
$iv=substr($tmp, 32, 16);
$text=substr($tmp, 48);
$chash=hash_hmac('sha256', $iv.$text, $this->hmac_key, true);
if (!hash_equals($hash, $chash)) // XXX PHP 5.6->
        return false;
$tmp=mcrypt_decrypt(MCRYPT_RIJNDAEL_128, $this->decrypt_key, $text, MCRYPT_MODE_CBC, $iv);
return trim($tmp);
return substr($tmp, 0, -ord($tmp[strlen($tmp)-1]));
}

private function encrypt_token($token)
{
$iv_size=mcrypt_get_iv_size(MCRYPT_RIJNDAEL_128, MCRYPT_MODE_CBC);
$iv=mcrypt_create_iv($iv_size, MCRYPT_RAND);
$tmp=mcrypt_encrypt(MCRYPT_RIJNDAEL_128, $this->decrypt_key, $token, MCRYPT_MODE_CBC, $iv);
$hash=hash_hmac('sha256', $iv.$tmp, $this->hmac_key, true);
return base64_encode($hash.$iv.$tmp);
}

/**
 * Client sends us the encrypted session cookie data, auth+decrypt it and set the session data.
 * Returns: true if ok, false if data is wrong
 */
private function setAuthdata($data)
{
if (!is_string($data) || $data===false)
	return false;

$s=explode(':', $data);
$this->session_id=$s[0];
$this->session_name=$s[1];
$this->session_token=$s[2];
$this->uid=(int)$s[3];

if ($this->uid<1)
	return false;

$this->be->set_session_data($this->session_id, $this->session_name, $this->session_token, $this->uid);

return true;
}

public function check_auth()
{
$tmp=$this->decrypt_token($this->token);

if ($tmp===false)
        return false;
return $this->setAuthdata($tmp);
}

public function get_user()
{
$u=$this->d->get_user();

$u['apitoken']=$this->encrypt_token($u['apitoken']);

return $u;
}

public function login()
{
return $this->d->login();
}

public function logout()
{
return $this->d->logout();
}

public function auth_apikey($key)
{
// XXX: Add drupal backend client key check
return true;
}

// Locations
public function get_locations()
{
return $this->d->retrieve_view('locations');
}

// Categories
public function get_categories()
{
// return $this->d->retrieve_view('categories');
return false;
}

// Files (images)
public function upload_file($file, $filename=null)
{
return $this->d->upload_file($file, $filename, true);
}

public function view_file($fid, $data=false)
{
return $this->d->view_file($fid, $data, false);
}

// Products
public function create_product($type, $sku, $title, $price)
{
return $this->d->create_product($type, $sku, $title, $price);
}

public function index_products()
{
return $this->d->index_products();
}

public function get_product($id)
{
return $this->d->get_product($id);
}

public function get_product_by_sku($sku)
{
return $this->d->get_product_by_sku($sku);
}


}
