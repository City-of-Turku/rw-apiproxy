<?php
require_once('DrupalServiceAPIClient.class.php');

class DrupalProductAPI
{
public $user_id=1;
public $vendor_id=0;
private $api;
// APIkey salt
private $salt='S24dfg765mncd3sdfg678kjfg24&/!@DFgERWT23Yaa!#&asf!GDF/H@£';
private $debug=false;
private $node_type='product';

// node (Base node) or product (Commerce product)
private $target='product';

// Fields map
private $fields;

// Category ID to Type map, must be set if target is 'product'
// TaxoID - Product type
// 1=>'furniture'
// 2=>'furniture'
private $cmap;

// Category to type map, idstring => Drupal Commerce Product type machine name
// cat0002 => book
private $ctmap;

private $clmap;

/*
 * url - Drupal service API url
 * user - API username
 * pwd - API password
 * cmap - Category mapping array key is category from camera, value is drupal taxonomy id
 */
public function __construct($url, $user, $pwd, array $cmap)
{
$this->api=new DrupalServiceAPIClient($url);
if ($user!=='' && $pwd!=='') {
	$this->api->set_auth_type(AUTH_SESSION);
	$this->api->set_auth($user, $pwd);
	$this->api->login();
}
$this->fields=array(
	'stock'=>'field_stock'
);
$this->cmap=$cmap;
$this->ctmap=array();
$this->clmap=array();
}

public function setCategoryTypeMap(array $ctmap)
{
$this->ctmap=$ctmap;
}

public function setClassTypeMap(array $clmap)
{
$this->clmap=$clmap;
}

public function getCategories()
{
throw new Exception('Not implemented');
}

protected function upload_file($file, $filename)
{
slog("Uploading file", $file);
$r=$this->api->upload_file($file, $filename, true);
return $r->fid;
}

public function getProductFromSku($sku)
{
$req=array(
	'field_sku'=>$sku
);
return $this->api->index_nodes($req);
}

protected function addProduct(Product $p)
{
if ($p->category===false || !is_string($p->category))
	throw new Exception('Product category must be set!');

if (!array_key_exists($p->category, $this->ctmap))
	throw new Exception('Product category is not of known type!');

$type=$this->ctmap[$p->category];

$fields=array();

$fids=$this->addProductImagesFromUpload($p->images);
$images=array();
foreach ($fids as $fid) {
        $images[]=array('fid'=>$fid);
}

$fields['field_image']=$images;
$fields['commerce_stock']=(int)$p->quantity;

// Location id
if ($p->location!==false)
	$fields['field_varasto']=$p->location;

// Class taxonomy
if ($p->class!==false)
	$fields['field_luokka']=array($p->class);

// $fields['field_category']=$this->ctmap[$p->category];

return $this->api->create_product($type, $p->sku[0], 'Vasta lisätty', 0, $fields);
}

protected Function addProductImagesFromUpload(array $images)
{
$fids=array();
$c=count($images['name']);
for ($i=0;$i<$c;$i++) {
	if ($images['error'][$i]!=0)
		continue;

	$fids[]=$this->upload_file($images['tmp_name'][$i], $images['name'][$i]);
}
return $fids;
}

}
