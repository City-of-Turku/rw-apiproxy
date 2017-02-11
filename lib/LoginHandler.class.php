<?php

/**
 * Handle user authentication to services
 *
 */
class LoginHandler
{
private $isClientAuth=false;
private $isAuth=false;
private $token;
private $decrypt_key='7e2bd40c210d25c738318f706';
private $hmac_key='';
//
private $uid;
private $session_id;
private $session_name;
private $session_token;
//
private $config;
private $appdata;
private $be;

public function __construct(array $config, array $app, &$be)
{
$this->be=$be;
$this->appdata=$app;
$h=getallheaders();

if (!$this->checkAuthenticationKey($h))
	return;

if (empty($h['X-Auth-Token']))
	return;

$this->token=$h['X-Auth-Token'];
$this->isAuth=$this->checkAuthToken();
}

private function checkAuthenticationKey(array $h)
{
// XXX: Check against authorized client keys
if (!empty($h['X-AuthenticationKey'])) {
	$this->isClientAuth=true;
	return true;
}
$this->isClientAuth=false;
$this->isAuth=false;
return false;
}

/**
 * Is the client (not user) authenticated with client key ?
 */
public function isClientAuthenticated()
{
return $this->isClientAuth;
}

/**
 * Is the user authenticated ?
 */
public function isAuthenticated()
{
return $this->isAuth;
}

public function getSession()
{
}

/**
 * Client send us the encrypted session cookie data, auth+decrypt it and set the session data.
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

/**
 * Check token sent by client, if we have it stored the we are good to go
 *
 */
protected function checkAuthToken()
{
$tmp=$this->decrypt_token($this->token);

if ($tmp===false)
	return false;

return $this->setAuthdata($tmp);
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
 * Login user with username/password supplied in the request
 */
public function login()
{
$r=Flight::request()->data;

if (empty($r['username']) || empty($r['password'])) {
	throw new ResponseException('Login operation missing required parameters', 400);
}

$username=$r['username'];
$password=$r['password'];

try {
	$this->be->set_auth($username, $password);
	$u=$this->be->login();
	$u['apitoken']=$this->encrypt_token($u['apitoken']);

	// Fill in the current application data, if set
	if (is_array($this->appdata)) {
		$u['app']=array(
		'version'=>$this->appdata['version'],
		'package'=>$this->appdata['apk']
		);
	}

	Flight::json(Response::data(200, 'Login OK', 'login', $u));
} catch (Exception $e) {
	Flight::json(Response::data(403, 'Login failure', 'login', array('error'=>$e->getMessage())), 403);
	return false;
}

return true;
}

public function logout()
{
Flight::json(Response::data(200, 'Logout OK', 'logout'));
}

public function userCurrent()
{
Flight::json(Response::data(200, 'User data', 'user', array()));
}

public function user($uid)
{
$u=$this->be->retrieve_user($uid);
Flight::json(Response::data(200, 'User data', 'user', $u));
}

} // class
