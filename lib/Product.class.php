<?php
/**
 * Product information base class
 */
class Product
{
public $id=null;
public $vendor;
public $sku;
public $name;
public $description=false;
public $price=false;
public $tax=false;
public $location=false;
public $weight=false;
public $height=false;
public $width=false;
public $depth=false;
public $color=false;
public $condition=false;
public $category;
public $quantity=1;
public $class=false;
public $images;
public $ean='';
}
