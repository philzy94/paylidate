<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'user_id','name','image','product_number','price','quantity','description'
    ];
}
