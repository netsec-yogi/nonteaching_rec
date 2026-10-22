<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Every write path fills models from Form Request `validated()` output or
 * explicit arrays built in services, so only the key is guarded.
 */
abstract class BaseModel extends Model
{
    protected $guarded = ['id'];
}
