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

public function __construct(LoginHandler &$l, BackendActionsInterface &$be)
{
$this->l=$l;
$this->api=$be;

// Product attributes key=>value mappings
$this->cmap=json_decode(file_get_contents('colormap.json'), true);
$this->umap=json_decode(file_get_contents('usagemap.json'), true);
$this->umapr=array_flip($this->umap);
$this->catmap=json_decode(file_get_contents('categorymap.json'), true);
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
if (!$this->api->validateBarcode($barcode))
	return Flight::json(Response::data(500, 'Invalid barcode', 'searchBarcode'), 500);

$filter=array('sku'=>$barcode);
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

slog($url);

$data=@file_get_contents($url);
header("Content-Length: ".strlen($data));
die($data);
}

public Function getProductImage($style, $fid)
{
$style=filter_var($style, FILTER_SANITIZE_STRING);
$fid=filter_var($fid, FILTER_VALIDATE_INT);

slog($style.$fid);

// We allow anonymous retrieval of product images, client must still be authenticated with client key
if (!$this->l->isClientAuthenticated())
	return Flight::json(Response::data(401, 'Client is not authenticated', 'image'), 401);

if (!is_numeric($fid))
	return Flight::json(Response::data(400, 'Invalid image identifier', 'image'), 400);

try {
	$file=$this->api->view_file($fid, false, true);
} catch (Exception $e) {
	return Flight::json(Response::data(500, 'Image details load failed', 'image', array('line'=>$e->getLine(), 'error'=>$e->getMessage())), 500);
}

if (!property_exists($file, "image_styles"))
	return Flight::json(Response::data(500, 'Image style error', 'image'), 500);

$styles=$file->image_styles;

if (!property_exists($styles, "$style"))
	return Flight::json(Response::data(412, 'Image style not configured', 'image'), 412);

return $this->dumpImageUrl('image/jpeg', $styles->$style);
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
		return Flight::json(Response::data(500, 'Invalid category filter', 'browse'), 500);
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
$r=Flight::request()->query;
$ip=$page===false ? (int)$r['page'] : $page;
$a=$limit===false ? (int)$r['amount'] : $limit;

if ($ip<1 || $ip>5000 || $a<1 || $a>50) {
	return Flight::json(Response::data(500, 'Invalid page or amount', 'products'), 500);
}

$ps=array();
try {
	$ps=$this->api->index_products($ip, $a, $filter, $sortby);
} catch (Exception $e) {
	Flight::json(Response::data(500, 'Data load failed', 'product', array('line'=>$e->getLine(), 'error'=>$e->getMessage())), 500);
	return false;
}

// Special case, search specific barcode
if (count($ps)===0 && $page==1 && $limit==1 && is_array($filter))
	return Flight::json(Response::data(404, 'Product not found', 'searchBarcode'), 404);

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
 * Add new product to Backend
 */
public Function add()
{
if (!$this->l->isAuthenticated())
	return Flight::json(Response::data(401, 'Client is not authenticated', 'browse'), 401);

$fer='';

try {
	$rf=Flight::request()->files;
	$r=$this->api->add_product(Flight::request()->data, $rf['images'], $fer);
	return Flight::json(Response::data(201, 'Product add', 'product', array("response"=>$r, "file_errors"=>$fer)), 201);
} catch (Exception $e) {
	// XXX: Handle errors properly
	$data=array('error'=>$e->getMessage());
	slog('Invalid product data', false, $e);
	return Flight::json(Response::data(400, 'Invalid product data', 'product', $data), 400);
}

}

public Function update()
{
if (!$this->l->isAuthenticated())
	return Flight::json(Response::data(401, 'Client is not authenticated', 'browse'), 401);

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
if (!$this->l->isAuthenticated())
	return Flight::json(Response::data(401, 'Client is not authenticated', 'categories'), 401);
Flight::json(Response::data(200, 'Categories', 'categories', $this->catmap));
}

}
