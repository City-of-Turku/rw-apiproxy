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

private $api_config;
private $config;

private $umap;

public function __construct(array $api, $array $config)
{
$this->d=new DrupalServiceAPIClient($config['url']);
$this->d->set_auth_type(AUTH_SESSION);
$this->hmac_key=$config['hmac_key'];
$this->decrypt_key=$config['key'];
$this->umap=array();
}

/**
 * Map usage taxonomy IDs.
 */
private Function purposeMap($u)
{
if (array_key_exists($u, $this->umap))
        return $this->umap[$u];
slog('Purpose not found in map', json_encode($u));
return false;
}

private Function purposeMapReverse($u)
{
if (array_key_exists($u, $this->umapr))
        return $this->umapr[$u];
slog('Purpose id not found in reverse map', json_encode($u));
return 0;
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

protected Function setProductImages(array $images, $style)
{
global $api;

$p=array();
foreach ($images as $img) {
        // Check that response is valid
        if (!is_object($img))
                continue;
        // Check that it is indeed an image
        if ($img->type!='image')
                continue;

        $p[]=sprintf('%s/product/image/%s/%d', $api['api_base_url'], $style, $img->fid);
}
return $p;
}

protected Function drupalJSONtoProduct(stdClass $po)
{
//echo json_encode($po); die();
$p=array();
$p['id']=$po->product_id;
$p['uid']=$po->uid;
$p['barcode']=$po->sku;
$p['title']=$po->title;
$p['status']=$po->status;
$p['stock']=$po->commerce_stock;
$p['created']=$po->created;
$p['category']=$this->categoryMap($po->type);
$p['subcategory']=$this->categorySubMap($po->type);
// Check for a body field!
$p['description']=$po->title; // XXX
$p['images']=array();
if (property_exists($po, "field_location")) {
	$p['location']=$po->field_location;
}
if (property_exists($po, "field_image")  && !is_null($po->field_image)) {
	$i=$this->setProductImages($po->field_image, 'normal');
	$p['images']=$i;
	if (count($i)>0) {
		$t=$this->setProductImages($po->field_image, 'thumbnail');
		$p['thumbnail']=$t[0];
	}
}
if (property_exists($po, "field_paino")) {
	// Always in Kg!
	$p['size']['weight']=$po->field_paino;
}
if (property_exists($po, "field_color")) {
	$p['color']=$po->field_color;
}
if (property_exists($po, "field_material")) {
	$p['material']=$po->field_material;
}
if (property_exists($po, "field_koko") && is_object($po->field_koko)) {
	// Always in cm!
	$a=$po->field_koko;
	$p['size']['depth']=$po->field_koko->length; // Make more sense
	$p['size']['width']=$po->field_koko->width;
	$p['size']['height']=$po->field_koko->height;
}
if (property_exists($po, "field_ean")) {
	$p['ean']=$po->field_ean;
}
if (property_exists($po, "field_isbn")) {
	$p['isbn']=$po->field_isbn;
}
if (property_exists($po, "field_purpose")) {
	// XXX: Values need to be mapped!!!
	$p['purpose']=$this->purposeMapReverse($po->field_purpose);
} else {
	$p['purpose']=0;
}

return $p;
}

public function index_products($page=0, $pagesize=20, array $filter=null, array $sortby=null)
{
$data=$this->d->index_products($page, $pagesize, null, $filter, $sortby);
$ps=array();
foreach ($data as $po) {
	$ps[$po->sku]=$this->drupalJSONtoProduct($po);
}
return $ps;
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
