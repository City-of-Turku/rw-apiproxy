<?php
/**
 *
 * Low-Level Drupal service methods
 *
 * This code is released under the GNU General Public License.
 *
 */

define('DRUPAL_LANGUAGE_NONE', 'und');

define('AUTH_ANONYMOUS', 0);
define('AUTH_BASIC', 1);
define('AUTH_SESSION', 2);

class DrupalServiceException extends
Exception {
public $response;
}
class DrupalServiceNotFoundException extends DrupalServiceException { }
class DrupalServiceAuthException extends DrupalServiceException { }

class DrupalServiceResponse
{
public $status;
public $json;

function __construct($status, $json)
{
$this->status=$status;
$this->json=$json;
}

}

class DrupalServiceAPIClient
{
// API url
protected $url;
protected $debug=false;
protected $uid=false;

// Basic auth username and password
protected $auth=0;
protected $username;
protected $password;
private $session_cookie=null;
private $csrf_token=null;

// API key auth (WIP)
protected $apikey;

// Current language
protected $language;

// Product currency
protected $currency='EUR';

function __construct($url)
{
$this->url=$url;
$this->language=DRUPAL_LANGUAGE_NONE;
}

public function set_language($l)
{
$this->language=$l;
}

public function set_currency($c)
{
$this->currency=$c;
}

public function set_auth($username, $password)
{
if (!is_string($username))
	throw new DrupalServiceException('Invalid username', 500);
if (!is_string($password))
	throw new DrupalServiceException('Invalid password', 500);
$this->username=$username;
$this->password=$password;
}

public function login()
{
return $this->login_session();
}

public function set_debug($bool)
{
$this->debug=$bool;
}

private function getcurl($url)
{
$curl=curl_init($url);
$header=array( 'Content-Type: application/json');
if (is_string($this->csrf_token))
	$header[]='X-CSRF-Token: '.$this->csrf_token;

$options=array(
	CURLOPT_HEADER => FALSE,
	CURLOPT_RETURNTRANSFER => TRUE,
	CURLINFO_HEADER_OUT => TRUE,
	CURLOPT_HTTPHEADER => $header);
curl_setopt_array($curl, $options);

if (is_string($this->session_cookie))
	curl_setopt($curl, CURLOPT_COOKIE, $this->session_cookie);

return $curl;
}

protected function handleStatus($status, $error, $response)
{
switch ($status) {
	case 0:
		throw new DrupalServiceException('CURL Error: '.$error, $status);
	case 200:
		return true;
	case 403:
	case 401:
		throw new DrupalServiceAuthException('Authentication error: '.$response, $status);
	case 404:
		throw new DrupalServiceException('Not found', $status);
	default:
		throw new DrupalServiceException($response, $status);
}

}

protected function executeGET($endpoint, array $query=null)
{
$url=$this->url.'/'.$endpoint;
if (is_array($query))
	$url.='?'.http_build_query($query);

$curl=$this->getcurl($url);
curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'GET');

if ($this->debug)
	slog('GET', $url);

$response=curl_exec($curl);
$status=curl_getinfo($curl, CURLINFO_HTTP_CODE);
$error=curl_error($curl);
curl_close($curl);

$this->handleStatus($status, $error, $response);

return $response;
}

protected function executeDELETE($endpoint)
{
$url=$this->url.'/'.$endpoint;
$curl=$this->getcurl($url);
curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'DELETE');

if ($this->debug)
	slog('DELETE', $url);

$response=curl_exec($curl);
$status=curl_getinfo($curl, CURLINFO_HTTP_CODE);
$error=curl_error($curl);
curl_close($curl);

$this->handleStatus($status, $error, $response);

return $response;
}

protected function executePOST($endpoint, $data)
{
$url=$this->url.'/'.$endpoint;

$curl=$this->getcurl($url);
curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
curl_setopt($curl, CURLOPT_POSTFIELDS, $data);

if ($this->debug)
	slog('POST', array($url, $data));

$response=curl_exec($curl);
$status=curl_getinfo($curl, CURLINFO_HTTP_CODE);
$error=curl_error($curl);
curl_close($curl);

$this->handleStatus($status, $error, $response);

return $response;
}

protected function executePUT($endpoint, $data)
{
$url=$this->url.'/'.$endpoint;

$curl=$this->getcurl($url);
curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'PUT');
curl_setopt($curl, CURLOPT_POSTFIELDS, $data);

if ($this->debug)
	slog('PUT', array($url, $data));

$response=curl_exec($curl);
$status=curl_getinfo($curl, CURLINFO_HTTP_CODE);
$error=curl_error($curl);
curl_close($curl);

$this->handleStatus($status, $error, $response);

return $response;
}

/*******************************************************************
 * User
 *******************************************************************/

protected function login_session()
{
$user=array(
	'username'=>$this->username,
	'password'=>$this->password,
);

$data=json_encode($user);
$r=$this->executePOST('user/login.json', $data);
$this->user=json_decode($r);
$ud=$this->user->user;
$this->session_cookie=$this->user->session_name.'='.$this->user->sessid;
$this->csrf_token=$this->user->token;
$this->uid=$this->user->user->uid;

$ut=sprintf('%s:%s:%s:%d', $this->user->sessid, $this->user->session_name, $this->csrf_token, $this->uid);

$u=array();
$u['apitoken']=$ut;
$u['username']=$ud->name;
$u['uid']=$this->uid;
$u['created']=$ud->created;
$u['access']=$ud->access;
$u['email']=$ud->mail;
$u['roles']=$ud->roles;
slog('User', json_encode($this->user));
if (property_exists($ud, "field_name")) {
	// XXX
}
if (property_exists($ud, "field_image")) {
	// XXX
}

return $u;
}

public function set_session_data($id, $name, $token, $uid)
{
$this->session_cookie=$name.'='.$id;
$this->csrf_token=$token;
$this->uid=$uid;
}

public function get_user_data()
{
return $this->user;
}

public function index_users()
{
$r=$this->executeGET('user.json');
return json_decode($r);
}

public function retrieve_user($uid)
{
if ($uid===-1)
	$uid=$this->uid;
if (!is_numeric($uid))
	throw new DrupalServiceException('Invalid user ID', 500);
$tmp=sprintf('user/%d.json', $uid);
$r=$this->executeGET($tmp);
return json_decode($r);
}

/******************************************************************
 * Files
 ******************************************************************/

// 'create' or 'create_raw'
public function upload_file($file, $filename=null, $manage=true)
{
if(!file_exists($file))
	throw new DrupalServiceException('File does not exist', 404);

if(!is_readable($file))
	throw new DrupalServiceException('File is not readable', 403);

$tmp=array(
	'filesize' => filesize($file),
	'filename' => is_string($filename) ? $filename : basename($file),
	'file' => base64_encode(file_get_contents($file)),
	'uid' => $this->uid);
if (!$manage)
	$tmp['status']=0;

$data=json_encode($tmp);
$r=$this->executePOST('file.json', $data);
return json_decode($r);
}

// get any binary files
public function view_file($fid, $data=false, $styles=false)
{
if (!is_numeric($fid))
	throw new DrupalServiceException('Invalid file ID', 500);
$tmp=sprintf('file/%d.json', $fid);
$p=array(
	'file_contents'=>$data ? 1 : 0,
	'image_styles'=>$styles ? 1 : 0
);
$r=$this->executeGET($tmp, $p);
return json_decode($r);
}

// delete file
public function delete_file($fid)
{
if (!is_numeric($fid))
	throw new DrupalServiceException('Invalid file ID', 500);
$tmp=sprintf('file/%d.json', $fid);
$r=$this->executeDELETE($tmp);
return json_decode($r);
}

// get files list
public function index_files($page=0, $pagesize=20)
{
$p=array(
	'page'=>(int)$page,
	'pagesize'=>(int)$pagesize
);
$r=$this->executeGET('file.json');
return json_decode($r);
}


/******************************************************************
 * Nodes
 ******************************************************************/

public function retrieve_node($nid)
{
if (!is_numeric($nid))
	throw new DrupalServiceException('Invalid node ID', 500);
$tmp=sprintf('node/%d.json', $nid);
$r=$this->executeGET($tmp);
return json_decode($r);
}

protected function prepare_node_fields($title, $type, array $fields=null)
{
$data=array(
	'uid'=>$this->uid,
	'language'=>$this->language);
if (is_string($title))
	$data['title']=$title;
if (is_string($type))
	$data['type']=$type;

if (is_array($fields)) {
	foreach ($fields as $field=>$content) {
		$data[$field]=is_array($content) ? $content : array($this->language=>array('value'=>$content));
	}
}
return $data;
}

public function create_node($type, $title, array $fields=null)
{
$r=$this->executePOST('node.json', json_encode($this->prepare_node_fields($title, $type, $fields)));
return json_decode($r);
}

public function update_node($nid, $title, array $fields)
{
$r=$this->executePUT(sprintf('node/%d.json', $nid), json_encode($this->prepare_node_fields($title, null, $fields)));
return json_decode($r);
}

public function delete_node($nid)
{
if (!is_numeric($nid))
	throw new DrupalServiceException('Invalid node ID', 500);
if ($nid<0)
	throw new DrupalServiceException('Invalid node ID', 500);
$r=$this->executeDELETE(sprintf('node/%d.json', $nid));
return json_decode($r);
}

public function index_nodes($page=0, $pagesize=20, array $fields=null, array $params=null)
{
$param=array(
	'page'=>$page,
	'pagesize'=>$pagesize
);
if (is_array($fields))
	$param['fields']=$fields;
if (is_array($params))
	$param['parameters']=$params;

$r=$this->executeGET('node.json', $param);
return json_decode($r);
}

/******************************************************************
 * Commerce Product
 ******************************************************************/

protected function validate_product_sku($sku)
{
if (!is_string($sku))
	return false;
$sku=trim($sku);
if (empty($sku))
	return false;
if (strpos($sku, ',') !== false)
	return false;
// We require a bit more checks than that
if (strlen($sku)<3)
	return false;

return true;
}

protected function prepare_product_fields($type, $sku, $title, $price, array $fields=null)
{
// Type, Title, SKU, commerce_price_amount and commerce_price_currency_code are always required for products
$data=array(
	'title'=>$title,
	'sku'=>$sku,
	'type'=>$type,
	'commerce_price_amount'=>$price,
	'commerce_price_currency_code'=>$this->currency
);

// XXX 'uid'=>$this->uid

if (is_array($fields)) {
	foreach ($fields as $field=>$content) {
		$data[$field]=$content;
	}
}
return $data;
}

public function index_products($page=0, $pagesize=20, array $fields=null, array $filter=null, array $sortby=null)
{
$param=array(
	'limits'=>(int)$pagesize,
	'offset'=>(int)($page-1)*$pagesize
);
if (is_array($fields))
	$param['fields']=implode(',', $fields);
//if (is_array($params))
//	$param['parameters']=$params;
if (is_array($filter)) {
	foreach ($filter as $f=>$q) {
		$k=sprintf('filter[%s]', $f);
		$param[$k]=is_array($q) ? $q[0] : $q;
		$k=sprintf('filter_op[%s]', $f);
		$param[$k]=is_array($q) ? $q[1] : 'CONTAINS';
	}
}

if (is_array($sortby)) {
	$sb=array();
	$sm=array();
	foreach ($sortby as $f => $o) {
		$sb[]=$f;
		$sm[]=$o;
	}
	$param['sort_by']=implode(',', $sb);
	$param['sort_order']=implode(',', $sm);
}

$r=$this->executeGET('product.json', $param);
return json_decode($r);
}

public function create_product($type, $sku, $title, $price, array $fields=null)
{
if (!is_string($type) || trim($type)=='')
	throw new DrupalServiceException('Invalid product type', 500);
if (!$this->validate_product_sku($sku))
	throw new DrupalServiceException('Invalid product SKU', 500);
if (!is_string($title) || trim($title)=='')
	throw new DrupalServiceException('Invalid product title', 500);
if (!is_numeric($price) || $price<0)
	throw new DrupalServiceException('Invalid product price', 500);

//print_r($this); die();

$r=$this->executePOST('product.json', json_encode($this->prepare_product_fields($type, $sku, $title, $price, $fields)));
return json_decode($r);
}

public function get_product($pid)
{
if (!is_numeric($pid))
	throw new DrupalServiceException('Invalid product ID', 500);

$r=$this->executeGET(sprintf('product/%d.json', $pid));
return json_decode($r);
}

public function get_product_by_sku($sku)
{
if (!$this->validate_product_sku($sku))
	throw new DrupalServiceException('Invalid product SKU', 500);

$r=$this->executeGET(sprintf('product.json', array('sku'=>$sku)));
return json_decode($r);
}

public function update_product($pid, array $fields)
{
if (!is_numeric($pid))
	throw new DrupalServiceException('Invalid product ID', 500);
if (count($fields)==0)
	return true;
$r=$this->executePUT(sprintf('product/%d.json', $pid), json_encode($fields));
return json_decode($r);
}

public function delete_product($pid)
{
if (!is_numeric($pid))
	throw new DrupalServiceException('Invalid product ID', 500);
if ($pid<0)
	throw new DrupalServiceException('Invalid product ID', 500);
$this->executeDELETE(sprintf('product/%d.json', $pid));
// We return true ok success blindly, as any error code (404, etc) throws an exception
return true;
}

/******************************************************************
 * Commerce Product Order
 ******************************************************************/

public function index_orders($page=0, $pagesize=20, array $fields=null, array $filter=null)
{
$param=array(
	'limits'=>(int)$pagesize,
	'offset'=>(int)($page-1)*$pagesize
);
if (is_array($fields))
	$param['fields']=implode(',', $fields);
if (is_array($filter)) {
	foreach ($filter as $f=>$q) {
		$k=sprintf('filter[%s]', $f);
		$param[$k]=is_array($q) ? $q[0] : $q;
		$k=sprintf('filter_op[%s]', $f);
		$param[$k]=is_array($q) ? $q[1] : 'CONTAINS';
	}
}

$r=$this->executeGET('order.json', $param);
return json_decode($r);
}

/******************************************************************
 * Views
 ******************************************************************/

public function retrieve_view($name)
{
if (!is_string($name))
	throw new DrupalServiceException('Invalid view name', 500);
$tmp=sprintf('views/%s.json', $name);
$r=$this->executeGET($tmp);
return json_decode($r);
}

}
?>
