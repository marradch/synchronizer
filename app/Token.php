<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Token extends Model
{
    protected $fillable = [
    	'token',    	        
    ];

    protected $table = 'offline_token';
}