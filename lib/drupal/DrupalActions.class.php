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

// Category map
private $cmap;

// Color taxonomy map
private $comap;
private $comapr;

// Usage/Purpose map
private $umap;
private $umapr;

// Field map
private $map;

private $aes;

private $dsn;
private $dbuser;
private $dbpass;

private $rolemap;

public function __construct(array $api, array $config)
{
$this->d=new DrupalServiceAPIClient($config['url']);
$this->cmap=array();
$this->comap=array();
$this->comapr=array();
$this->umap=array();
$this->umapr=array();

$this->aes=new AES($config['key'], $config['hmac_key']);

$this->dsn=$config['apikey_dsn'];
$this->dbuser=$config['apikey_user'];
$this->dbpass=$config['apikey_password'];

if (isset($config['debug']) && $config['debug'])
	$this->d->set_debug(true);

if (isset($config['api_username']) && $config['api_password'])
	$this->d->set_api_auth($config['api_username'], $config['api_password']);

// Drupal role to client role
$this->rolemap=json_decode(file_get_contents('drupal-rolesmap.json'), true);

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
	'required'=>false,
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
	'separator'=>';',
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

//file_put_contents('/tmp/drupal-field-map.json', json_encode($this->map, JSON_PRETTY_PRINT));

}

/**
 * Map usage taxonomy IDs.
 */
private Function purposeMap($u)
{
if (array_key_exists($u, $this->umap))
        return $this->umap[$u];
slog('Purpose taxonomy not found in map', json_encode($u));
return false;
}

private Function purposeMapReverse($u)
{
if (array_key_exists($u, $this->umapr))
        return $this->umapr[$u];
slog('Purpose taxonomy id not found in reverse map', json_encode($u));
return 0;
}

/**
 * Map color as string into a taxonomy ID, this is instance specific so
 * we load the map from a json file.
 */
private Function colorMap($c)
{
if (array_key_exists($c, $this->comap))
	return $this->comap[$c];
slog('Color string not found in map', json_encode($c));
return false;
}

/**
 * Map color taxonomy ID to color string.
 */
private Function colorMapReverse($c)
{
if (array_key_exists($c, $this->comapr))
	return $this->comapr[$c];
slog('Color ID not found in map', json_encode($c));
return false;
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

public Function setCategoryMap(array &$m)
{
$this->cmap=$m;
}

public Function setColorMap(array &$m)
{
$this->comap=$m;
$this->comapr=array_flip($m);
}

public Function setUsageMap(array &$m)
{
$this->umap=$m;
$this->umapr=array_flip($m);
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

if (is_array($this->rolemap) && $u['roles']) {
	$r=array();
	foreach ($u['roles'] as $role) {
		if (isset($this->rolemap[$role]))
			$r=array_merge($r, $this->rolemap[$role]);
	}
	$u['roles']=array_values($r);
}

return $u;
}

public function logout()
{
return $this->d->logout();
}

public function auth_apikey($key)
{
try {
    $dbh=new PDO($this->dsn, $this->dbuser, $this->dbpass);
    $stmt=$dbh->prepare('SELECT count(*) AS c FROM apikeys WHERE apikey=? AND revoked=0');
    $stmt->bindParam(1, $key);
    $r=$stmt->execute();
    if (!$r) {
        slog('Failed to query API key database');
	return false;
    }

    $row=$stmt->fetch();

    // Close connection
    $stmt=null;
    $dbh=null;

    if ($row['c']===1)
	return true;
} catch (PDOException $e) {
    slog('API Key database failure',false,$e);
    return false;
}

return false;
}

// Locations
public function get_locations()
{
return $this->d->retrieve_resource('locations');
}

// Categories
public function get_categories()
{
//return $this->d->retrieve_view('categories');
return false;
}

public function get_colors()
{
$t=$this->d->retrieve_resource('colors', true);

$r=array();
foreach ($t as $c) {
	$r[$c['tid']]=array('cid'=>$c['tid'], 'code'=>$c['code'], 'color'=>$c['name']);
}

return $r;
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
	$ur=$this->upload_file($images['tmp_name'][$i], $images['name'][$i]);
	slog('Uploaded', $ur);
	$fids[]=$ur->fid;
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
        slog('Invalid drupal product data', json_encode($er));
	throw new ProductErrorException('Invalid product data for Drupal Commerce', 400);
}

$fer=array();
$images=array();
if (count($files)>0) {
        $fids=$this->addImagesFromUpload($files, $fer);
        foreach ($fids as $fid) {
                $images[]=array('fid'=>$fid);
        }
        $f['field_image']=$images;
} else {
        slog('No images given for product');
	throw new ProductImageException('Product image(s) are required', 400);
}

// XXX: Client supports this now so... fix it!
$price=0;
$r=$this->create_product($f['type'], $f['sku'], $f['title'], $price, $f);
slog('Product added', $f);
return true;
}

// Products
public function create_product($type, $sku, $title, $price, array $f)
{
return $this->d->create_product($type, $sku, $title, $price, $f);
}

protected Function setProductImage(stdClass $image, $style)
{
return $this->setProductImages(array($image), $style);
}

protected Function setProductImages(array $images, $style)
{
// XXX
global $api;

$p=array();
foreach ($images as $img) {
        // Check that response is valid
        if (!is_object($img))
                continue;
        // Check that it is indeed an image
        if ($img->type!='image')
                continue;

        $p[]=sprintf('%s/images/%s/%d', $api['api_base_url'], $style, $img->fid);
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
	// In case the image field is limited to one, then we get an object direclty, handle this special case
	$i=false;
	if (is_object($po->field_image)) {
		$i=$this->setProductImage($po->field_image, 'normal');
		$p['images']=$i;
		$t=$this->setProductImage($po->field_image, 'thumbnail');
		$p['thumbnail']=$t[0];
	} else if (is_array($po->field_image)) {
		$i=$this->setProductImages($po->field_image, 'normal');
		$p['images']=$i;
		if (count($i)>0) {
			$t=$this->setProductImages($po->field_image, 'thumbnail');
			$p['thumbnail']=$t[0];
		}
	}
}
if (property_exists($po, "field_paino")) {
	// Always in Kg!
	$p['size']['weight']=$po->field_paino;
}
if (property_exists($po, "field_color")) {
	// Handle multiple colors, then they are arrays
	$p['color']=array();

	if (is_array($po->field_color)) {
		foreach ($po->field_color as $c)
			$p['color'][]=$this->colorMapReverse($c);
	} else {
		$p['color'][]=$this->colorMapReverse($po->field_color);
	}
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

/**
 * Products
 */

public function index_products($page=0, $pagesize=20, array $filter=null, array $sortby=null)
{
$data=$this->d->index_products($page, $pagesize, null, $filter, $sortby);
$ps=array();
foreach ($data as $po) {
	// Note: Don't use any index as otherwise sorting won't work
	$ps[]=$this->drupalJSONtoProduct($po);
}
return $ps;
}

public function get_product($id)
{
return $this->d->get_product($id);
}

public function get_product_by_sku($sku)
{
$r=$this->d->get_product_by_sku($sku);

if (is_array($r) && count($r)===0)
	throw new ProductNotFoundException("Not product with requested SKU", 404);

return $r;
}

protected function lineItemToOrderItem(stdClass $pr)
{
$oi=array();
$oi['id']=(int)$pr->line_item_id;
$oi['sku']=$pr->line_item_label;
$oi['title']=$pr->line_item_title;
$oi['type']=$pr->type;
$oi['amount']=(int)$pr->quantity;

return $oi;
}

/**
 * Orders
 */
protected function drupalJSONtoOrder($id, stdClass $o)
{
$p=array();

$p['id']=(int)$id;
$p['status']=$o->status;
$p['user']=(int)$o->uid;

$p['created']=(int)$o->created;
$p['changed']=(int)$o->changed;

$p['amount']=(int)$o->commerce_order_total->amount;
$p['currency']=$o->commerce_order_total->currency_code;

// Get the ID numbers
$billing_id=$o->commerce_customer_billing;
$shipping_id=$o->commerce_customer_shipping;

if (property_exists($o, "commerce_line_items_entities")) {
	$ois=array();
	foreach ($o->commerce_line_items_entities as $id=>$pr)
		$ois[]=$this->lineItemToOrderItem($pr);
	$p['items']=$ois;
}

if (property_exists($o, "commerce_customer_billing_entities") && !is_null($billing_id)) {
	$p['billing']=$this->drupalAddressFieldToArray($o->commerce_customer_billing_entities->$billing_id->commerce_customer_address);
}

if (property_exists($o, "commerce_customer_shipping_entities") && !is_null($shipping_id)) {
	$p['shipping']=$this->drupalAddressFieldToArray($o->commerce_customer_shipping_entities->$shipping_id->commerce_customer_address);
}

if (property_exists($o, "field_email")) {
	$p['email']=$o->field_email;
}

return $p;
}

/* Convert the drupal address format to something more common:
	{"country":"FI",
	"administrative_area":"",
	"sub_administrative_area":null,
	"locality":"City",
	"dependent_locality":"",
	"postal_code":"12345",
	"thoroughfare":"Street name",
	"premise":"",
	"sub_premise":null,
	"organisation_name":null,
	"name_line":"Name",
	"first_name":"Turun",
	"last_name":"",
	"data":null}
*/
protected function drupalAddressFieldToArray(stdClass $a)
{
$r=array();

$r['postal_code']=$a->postal_code;
$r['address']=$a->thoroughfare;
$r['name']=$a->name_line;
$r['city']=$a->locality;
$r['country']=$a->country;
$r['org']=$a->organisation_name;

return $r;
}

public function index_orders($page=0, $pagesize=20, array $filter=null, array $sortby=null)
{
$data=$this->d->index_orders($page, $pagesize, null, $filter, $sortby);
$ps=array();
if (!$data)
	return $ps;
foreach ($data as $id=>$o) {
	// Note: Don't use any index as otherwise sorting won't work anymore.
	$ps[]=$this->drupalJSONtoOrder($id, $o);
}
return $ps;
}

public function set_order_status($oid, $status)
{
try {
	$o=$this->d->set_order_status($oid, $status);
	return $this->drupalJSONtoOrder($o->order_number, $o);
} catch (DrupalServiceNotFoundException $e) {
	throw new OrderNotFoundException("Order $oid not found", 404, $e);
}
}

protected function cart($clear=false)
{
if ($clear) {
	$data=$this->d->create_cart();
	// When clearing/creating we get a nice response!
	return $this->drupalJSONtoOrder($data->order_number, $data);
}

// but...
$data=$this->d->index_cart();
$cart=null;
// Loop over the "one" property that is a number
foreach ($data as $c) {
	$cart=$c;
}

if (is_object($cart)) {
	return $this->drupalJSONtoOrder($cart->order_number, $cart);
}
return false;
}

public function add_to_cart($sku, $quantity)
{
try {
	return $this->d->add_to_cart_by_sku($sku, $quantity);
} catch (DrupalServiceNotFoundException $e) {
	throw new OrderNotFoundException("Product not found", 404, $e);
} catch (DrupalServiceConflictException $e) {
	throw new OrderOutOfStockException("Product out of stock", 409, $e);
}

}

public function index_cart()
{
return $this->cart(false);
}

public function clear_cart()
{
return $this->cart(true);
}

public function checkout_cart()
{
try {
	return $this->d->checkout_cart();
} catch (DrupalServiceNotFoundException $e) {
	throw new OrderNotFoundException("Cart not found", 404, $e);
} catch (DrupalServiceConflictException $e) {
	throw new OrderOutOfStockException("Cart products out of stock", 409, $e);
}
}

/**
 * mapVariable
 *
 *
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
		if (!is_string($v))
			$er[$id]='Invalid contents, not a string: '.$v;
		$v=trim($v);
		// Input is string, separated by something, result is array of strings (taxonomy for example)
		if (isset($o['separator'])) {
			$v=explode($o['separator'], $v);
		}
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
		if (isset($o['regexp_validate'])) {
			// XXX Implement
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
		if (!is_numeric($v))
			$er[$id]='Node reference must be a number.';
		$v=(int)$v;
		if ($v<1)
			$er[$id]='Node reference must be positive.';
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
		$v=$this->mapVariable($r, $id, $df, $o, $er);
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
