<?php

namespace App\Models;

use Database\Factories\WilayaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $code
 * @property string $name_fr
 * @property string $name_ar
 */
#[Fillable(['code', 'name_fr', 'name_ar'])]
class Wilaya extends Model
{
    /** @use HasFactory<WilayaFactory> */
    use HasFactory;

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'int';

    protected static function newFactory(): WilayaFactory
    {
        return WilayaFactory::new();
    }

    /**
     * @return HasMany<Baladiya, $this>
     */
    public function baladiyas(): HasMany
    {
        return $this->hasMany(Baladiya::class, 'wilaya_code', 'code');
    }
}
