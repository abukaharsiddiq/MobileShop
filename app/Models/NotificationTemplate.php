<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Event x channel er message, {placeholder} shoho. Bondho hole oi event e oi channel e message jay na.
class NotificationTemplate extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean'];
    }

    // {customer} ... bosano; na thaka placeholder faka
    public function render(array $vars): string
    {
        $text = strtr($this->body, collect($vars)->mapWithKeys(fn ($v, $k) => ['{' . $k . '}' => (string) $v])->all());

        return trim(preg_replace('/\{[a-z_]+\}/', '', $text));
    }
}
