<?php
require_once('DrupalServiceAPIClient.class.php');

class DrupalActions extends BackendActionsInterface
{
private $d;
private $uid;
private $session_id;
private $session_name;
private $session_token;

private $api_config;
private $config;

private $cmap;
private $umap;
private $umapr;
private $map;

private $aes;

public function __construct(array $api, array $config)
{
$this->d=new DrupalServiceAPIClient($config['url']);
$this->umap=array();
$this->umapr=array();

$this->aes=new AES($config['key'], $config['hmac_key']);

// Client API to Drupal Product field mapping
$this->map=array(
 'sku'=>array(
	'id'=>'barcode',
	'required'=>true,
	'type'=>'string',
	'cb_validate'=>'validateBarcode'
	),
 'title'=>array(
	'id'=>'title',
	'required'=>true,
	'type'=>'string'
	),
 'type'=>array(
	'id'=>'category',
	'required'=>true,
	'type'=>'string',
	'cb_map'=>'categoryMap'
	),
 'commerce_stock'=>array(
	'id'=>'stock',
	'required'=>false,
	'default'=>1,
	'type'=>'int',
	'min_value'=>0,
	'max_value'=>99999
	),
 'field_varasto'=>array(
	'id'=>'location',
	'required'=>true,
	'type'=>'nodeid',
	),
 'field_location_detail'=>array(
	'id'=>'locationdetail',
	'ignore'=>true,
	'field_id'=>'value',
	'required'=>false,
	'type'=>'string',
	'cb_validate'=>'validateDescription'
	),
 'field_body'=>array(
	'id'=>'description',
	'field_id'=>'value',
	'required'=>false,
	'type'=>'string',
	'cb_validate'=>'validateDescription'
	),
 'field_purpose'=>array(
	'id'=>'purpose',
	'required'=>true,
	'type'=>'int',
	'cb_map'=>'purposeMap',
	'cb_map_r'=>'purposeMapReverse'
	),
 'field_paino'=>array(
	'id'=>array('weight'=>null, 'unit'=>'kg'), // Request variables that get analyzed (if value NULL, if not, then the value is used as is)
	'vid'=>array('weight', 'unit'),            // and translated to drupal field variables.
	'required'=>false,
	'type'=>'int',
	'min_val'=>1,
	'max_val'=>1000
	),
 'field_koko'=>array(
	'id'=>array('width'=>null, 'height'=>null, 'depth'=>null, 'unit'=>'cm'),
	'vid'=>array('width', 'height', 'length', 'unit'),
	'required'=>false,
	'type'=>'int',
	'min_val'=>1,
	'max_val'=>1000
	),
 'field_vari'=>array(
	'id'=>'color',
	'required'=>false,
	'type'=>'string',
	'cb_map'=>'colorMap'
	),
 'field_isbn'=>array(
	'id'=>'isbn',
	'required'=>false,
	'ignore_empty'=>true,
	'type'=>'string',
	'cb_validate'=>'validateEAN'
	),
 'field_ean'=>array(
	'id'=>'ean',
	'required'=>false,
	'ignore_empty'=>true,
	'type'=>'string',
	'cb_validate'=>'validateEAN'
	)
);

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

/**
 * categoryMap()
 *
 * Validate given category id against known categories.
 *
 */
public Function categoryMap($ts)
{
if (array_key_exists($ts, $this->cmap))
	return $ts;
return false;
}

public Function setCategoryMap(array &$cmap)
{
$this->cmap=$cmap;
}

protected Function categorySubMap($ts)
{
// XXX: Implement this
return 0;
}

public function set_auth($username, $password)
{
return $this->d->set_auth($username, $password);
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
$this->uid=(int)$s[3];

if ($this->uid<1) {
        slog('Invalid user id', $this->uid);
	return false;
}

slog('User data', json_encode($s));

$this->session_id=$s[0];
$this->session_name=$s[1];
$this->session_token=$s[2];

$this->d->set_session_data($this->session_id, $this->session_name, $this->session_token, $this->uid);

return true;
}

public function check_auth()
{
if (empty($this->token))
	return false;

$tmp=$this->aes->decrypt($this->token);

if ($tmp===false)
        return false;

return $this->setAuthdata($tmp);
}

public function get_user()
{

}

public function login()
{
$u=$this->d->login();
if ($u===false)
	throw new Exception('Authentication error', 403);

$u['apitoken']=$this->aes->encrypt($u['apitoken']);
return $u;
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
//return $this->d->retrieve_view('categories');
return false;
}

// Files (images)
public function upload_file($file, $filename=null)
{
return $this->d->upload_file($file, $filename, true);
}

public function view_file($fid, $data=false, $styles=false)
{
return $this->d->view_file($fid, $data, $styles);
}

protected Function addImagesFromUpload(array $images, array &$errors)
{
$fids=array();
$c=count($images['name']);
for ($i=0;$i<$c;$i++) {
        if ($images['error'][$i]!=0) {
                $errors[]=$images['name'][$i];
                continue;
        }
        $fids[]=$this->upload_file($images['tmp_name'][$i], $images['name'][$i]);
}
return $fids;
}

public function add_product(array $data, array $files)
{
$p=array();
$er=array();

$f=$this->mapRequest($data, $er);

if (count($er)>0) {
        $data=array('errors'=>$er);
        $data['f']=$f;
        slog('Invalid product data', json_encode($er));
	throw new Exception('Invalid product data', 400);
}

$fer=array();
$images=array();
if (count($files)>0) {
        $fids=$this->addImagesFromUpload($files, $fer);
        foreach ($fids as $fid) {
                $images[]=array('fid'=>$fid);
        }
        $f['field_image']=$images;
}

$price=0;
$r=$this->create_product($f['type'], $f['sku'], $f['title'], $price, $f);
slog('Product added', $f['sku']);
return true;
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

/**
 * mapVariable
 */
protected Function mapVariable(array &$r, $id, $df, array &$o, array &$er)
{
// Should we just ignore it
if (isset($o['ignore']) && $o['ignore']===true)
	return true;

// First check if it exist or not and if required
if ($o['required']===true && !isset($r[$id])) {
	$er[$id]='Required parameter is missing';
	return false;
} else if ($o['required']===false && !isset($r[$id]) && !isset($o['default'])) {
	return true;
}

// Set default value in case not set
if ($o['required']===false && !isset($r[$id]) && isset($o['default'])) {
	$v=$o['default'];
} else {
	$v=$r[$id];
}

// Check if empty value can be just ignored, or do we need this ?
//if ($o['required']===false &&

$type=$o['type'];
switch ($type) {
	case 'string':
		$v=trim($v);
		if (!is_string($v))
			$er[$id]='Invalid contents, not a string: '.$v;
		if (isset($o['cb_map'])) {
			$v=call_user_func(array($this, $o['cb_map']), $v);
			if ($v===false) {
				$er[$id]='Invalid value given, not found in map';
				return false;
			}
		}
		if (isset($o['cb_validate'])) {
			$v=call_user_func(array($this, $o['cb_validate']), $v);
			if ($v===false) {
				$er[$id]='Invalid value given, did not validate';
				return false;
			}
		}
	break;
	case 'int':
		if (!is_numeric($v))
			$er[$id]='Invalid contents, not a number '.$v;
		$v=(int)$v;
		if (isset($o['max_value']) && $v>$o['max_value'])
			$er[$id]='Value too large: '.$v;
		else if (isset($o['min_value']) && $v<$o['min_value'])
			$er[$id]='Value too small: '.$v;
		if (isset($o['cb_map'])) {
			$v=call_user_func(array($this, $o['cb_map']), $v);
			if ($v===false) {
				$er[$id]='Invalid value given, not found in map';
				return false;
			}
		}
		if (isset($o['cb_validate'])) {
			$v=call_user_func(array($this, $o['cb_validate']), $v);
			if ($v===false) {
				$er[$id]='Invalid value given, did not validate';
				return false;
			}
		}
	break;
	case 'nodeid':
		if (!is_numeric($v) || $v<1)
			$er[$id]='Invalid node reference ID';
		$v=(int)$v;
	break;
	default:
		$er[$id]='Unknown type. Invalid contents';
		return false;
}

return $v;
}

protected Function mapRequest(array $r, array &$er)
{
foreach ($this->map as $df => $o) {
	$id=$o['id'];

	if (is_array($id)) {
		// Map multiple request variables into one drupal field
		$vididx=0;
		$v=array();
		foreach ($id as $tid => $dv) {
			$fid=$o['vid'][$vididx];
			if (is_null($dv)) {
				$tmp=$this->mapVariable($r, $tid, $df, $o, $er);
				if ($tmp===false || $tmp===true)
					continue;
				$v[$fid]=$tmp;
			} else {
				$v[$fid]=$dv;
			}
			$vididx++;
		}
		if (count($v)==count($id))
			$f[$df]=$v;
	} else {
		// 1:1
		$v=$this->mapVariable($id, $df, $o, $er);
		if ($v===false || $v===true) // Skip or Error case, lets just collection any more errors
			continue;
		if (isset($o['field_id'])) {
			$f[$df][$o['field_id']]=$v;
		} else {
			$f[$df]=$v;
		}
	}
}

return $f;
}

}
