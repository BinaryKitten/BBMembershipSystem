<?php namespace BB\Entities;

use Illuminate\Database\Eloquent\Model;

class Activity extends Model
{

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'access_log';


    /**
     * Fillable fields
     *
     * @var array
     */
    protected $fillable = [
        'user_id', 'key_fob_id', 'response', 'service', 'delayed', 'created_at'
    ];


    public function user()
    {
        return $this->belongsTo('\BB\Entities\User');
    }

    public function keyFob()
    {
        return $this->belongsTo('\BB\Entities\KeyFob');
    }

} 