<?php

/**
 * Handle product related requests.
 */
class ProductHandler
{
private $l;
private $api;
private $map;
private $cmap;
private $umap;
private $umapr;
private $catmap;
private $validSort=array('title','sku','date');

public function __construct(LoginHandler $l)
{
global $drupal;

$this->l=$l;
$this->api=$drupal;

// Product attributes key=>value mappings
$this->cmap=json_decode(file_get_contents('colormap.json'), true);
$this->umap=json_decode(file_get_contents('usagemap.json'), true);
$this->umapr=array_flip($this->umap);
$this->catmap=json_decode(file_get_contents('categorymap.json'), true);

// Product field mapping
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

private Function validateDescription($desc)
{
return strip_tags($desc);
}

/**
 * Validate EAN/ISBN
 *
 * Checks if given code is 13 numbers
 * XXX: Does not *really* validate it properly yet
 *
 */
private Function validateEAN($bc)
{
return preg_match('/^[0-9]{13}$/', $bc)===1 ? $bc : false;
}

/**
 * Validate our barcode format.
 * We accept both (old) AAA123456 and (new) AAA123456789
 */
private Function validateBarcode($bc)
{
return preg_match('/^[A-Z]{3}[0-9]{6,9}$/', $bc)===1 ? $bc : false;
}

/**
 * Map color as string into a taxonomy ID, this is instance specific so
 * we load the map from a json file.
 */
private Function colorMap($c)
{
if (array_key_exists($c, $this->cmap))
	return $this->cmap[$c];
slog('Color not found in map', json_encode($c));
return false;
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
 * upload_file()
 *
 * Helper to request upload of file to Drupal API.
 *
 * Returns: Druapl File ID
 */
protected function upload_file($file, $filename)
{
$r=$this->api->upload_file($file, $filename, true);
return $r->fid;
}

/**
 *
 *
 */
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

public Function searchBarcode($barcode)
{
if (!$this->validateBarcode($barcode))
	return Flight::json(Response::data(500, 'Invalid barcode', 'search'));

$filter=array(
	'sku'=>$barcode
);

return $this->browseProducts(1, 1, $filter);
}

private Function dumpImageUrl($mime, $url)
{
// Cache for 10 minutes, for now.. we might up this as product images won't change afterwards
if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
	if (strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) < time() - 600) {
		header('HTTP/1.1 304 Not Modified');
		die();
	}
}
// Add local proxy cache for images
// for now tell client to cache for 24 hours
header('Expires: '.gmdate('D, d M Y H:i:s \G\M\T', time() + (60 * 60 * 24)));
header("Content-type: ".$mime);

$data=@file_get_contents($url);
header("Content-Length: ".strlen($data));
die($data);
}

public Function getProductImage($style, $fid)
{
$style=filter_var($style, FILTER_SANITIZE_STRING);
$fid=filter_var($fid, FILTER_VALIDATE_INT);

// We allow anonymous retrieval of product images, client must still be authenticated with client key
if (!$this->l->isClientAuthenticated())
	return Flight::json(Response::data(500, 'Client is not authenticated', 'image'));

if (!is_numeric($fid))
	return Flight::json(Response::data(400, 'Invalid image identifier', 'image'));

try {
	$file=$this->api->view_file($fid, false, true);
} catch (Exception $e) {
	return Flight::json(Response::data(500, 'Image details load failed', 'image', array('line'=>$e->getLine(), 'error'=>$e->getMessage())), 500);
}

if (!property_exists($file, "image_styles"))
	return Flight::json(Response::data(500, 'Image style error', 'image'));

$styles=$file->image_styles;

if (!property_exists($styles, "$style"))
	return Flight::json(Response::data(412, 'Image style not configured', 'image'));

return $this->dumpImageUrl('image/jpeg', $styles->$style);
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

protected Function dataToProduct(array $d)
{
$p=new Product();

$p->sku=$d['barcode'];
$p->title=$d['title'];
$p->category=$d['category'];
$p->stock=1;

return $p;
}

/**
 * drupalJSONtoProduct()
 *
 * Convert the JSON object returned by Drupal to a simpler
 * and easier to use format without all the Drupal specific details.
 *
 */
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

// XXX: Deprecated!
public Function search()
{
$this->browse();
}

/**
 * browse();
 *
 * Product browsing and search public API handler
 *
 */
public Function browse($page=1)
{
if (!$this->l->isAuthenticated())
	return Flight::json(Response::data(401, 'Client is not authenticated', 'browse'), 401);

$r=Flight::request()->query;
$filter=array(
	'commerce_stock'=>array(0, '>')
);

if (isset($r['q']))
	$filter['title']=filter_var(trim($r['q']), FILTER_SANITIZE_STRING);
else if (isset($r['string']))
	$filter['title']=filter_var(trim($r['string']), FILTER_SANITIZE_STRING);

if (isset($r['category'])) {
	$t=$this->categoryMap($r['category']);
	if ($t===false)
		return Flight::json(Response::data(500, 'Invalid category filter', 'browse'));
	$filter['type']=$t;
}

$sortby=array();
if (isset($r['o'])) {
 $o=$r['o'];
 switch ($o) {
  case 'sku':
  case 'title':
  case 'created':
   $sortby=array();
  break;
  default:
 }
} else {
 $sortby['created']='desc';
}

$this->browseProducts(false, false, $filter, $sortby);
}

/**
 * browseProducts();
 *
 * Does the actual call to Drupal
 *
 * $page page number, or false to get from request
 * $limit items per page, or false to get from request
 * $filter array of filtering variables
 *
 */
protected Function browseProducts($page=false, $limit=false, array $filter=null, array $sortby=null)
{
global $drupal;

$r=Flight::request()->query;
$ip=$page===false ? (int)$r['page'] : $page;
$a=$limit===false ? (int)$r['amount'] : $limit;

if ($ip<1 || $ip>5000 || $a<1 || $a>50) {
	return Flight::json(Response::data(500, 'Invalid page or amount', 'products'));
}

$ps=array();
try {
	$data=$drupal->index_products($ip, $a, null, $filter, $sortby);
	foreach ($data as $po) {
		$ps[$po->sku]=$this->drupalJSONtoProduct($po);
	}
} catch (Exception $e) {
	Flight::json(Response::data(500, 'Data load failed', 'product', array('line'=>$e->getLine(), 'error'=>$e->getMessage())), 500);
	return false;
}

$data=array('page'=>$ip, 'ramount'=>$a, 'amount'=>count($ps), 'products'=>$ps);
Flight::json(Response::data(200, 'Products', 'products', $data));
}

protected Function categoryMap($ts)
{
$vc=array("huonekalu", "laite", "kirja", "askartelu", "liikunta", "muu");
if (in_array($ts, $vc, true))
	return $ts;
return false;
}

protected Function categorySubMap($ts)
{
// XXX:!!!
return 0;
}

protected Function mapVariable($id, $df, array &$o, array &$er)
{
$r=Flight::request()->data;

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

protected Function mapRequest(array &$er)
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
				$tmp=$this->mapVariable($tid, $df, $o, $er);
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

/**
 * add()
 *
 * Add new product to Drupal Commerce.
 */
public Function add()
{
if (!$this->l->isAuthenticated())
	return Flight::json(Response::data(401, 'Client is not authenticated', 'browse'), 401);

$p=array();
$er=array();

$f=$this->mapRequest($er);

if (count($er)>0) {
	$data=array('errors'=>$er);
	$data['f']=$f;
	slog('Invalid product data', json_encode($er));
	return Flight::json(Response::data(400, 'Invalid product data', 'product', $data), 400);
}

//return Flight::json(Response::data(400, 'Product add', 'product', $f), 400);

$rf=Flight::request()->files;
$files=$rf['images'];

$fer=array();
$images=array();
if (count($files)>0) {
	$fids=$this->addImagesFromUpload($files, $fer);
	foreach ($fids as $fid) {
		$images[]=array('fid'=>$fid);
	}
	$f['field_image']=$images;
}

//return Flight::json(Response::data(400, 'Product add', 'product', array('images'=>$images)), 400);

$price=0;

$r=$this->api->create_product($f['type'], $f['sku'], $f['title'], $price, $f);

slog('Product added', $f['sku']);

Flight::json(Response::data(201, 'Product add', 'product', array("response"=>$r, "file_errors"=>$fer)), 201);
}

public Function update()
{
if (!$this->l->isAuthenticated())
	return Flight::json(Response::data(401, 'Client is not authenticated', 'browse'), 401);

$p=array();
$er=array();

$f=$this->mapRequest($er);

Flight::json(Response::data(500, 'Update not implemented', 'product'), 500);
}

protected Function get_product_from_response($data)
{
if (!is_object($data))
	return false;
// Services API returns a stupid object with product id as a property. Not very convinient that.
$prod=array_pop(get_object_vars($data));
if (!is_object($prod))
	return false;
return $prod;
}

protected Function get_by_id($pid)
{
$data=$this->api->get_product($pid);
return $this->get_product_from_response($data);
}

protected Function get_by_sku($sku)
{
$data=$this->api->get_product_by_sku($sku);
return $this->get_product_from_response($data);
}

public Function stockUpdate()
{
if (!$this->l->isAuthenticated())
	return Flight::json(Response::data(401, 'Client is not authenticated', 'stock'), 401);

Flight::json(Response::data(500, 'Stock handling not implemented', 'product'), 500);
}

public Function delete()
{
if (!$this->l->isAuthenticated())
	return Flight::json(Response::data(401, 'Client is not authenticated', 'browse'), 401);
Flight::json(Response::data(500, 'Delete not implemented', 'product'), 500);
}

public Function categories()
{
// Flight::lastModified(1234567890);
Flight::json(Response::data(200, 'Categories', 'categories', $this->catmap));
}

}
