<?php

include __DIR__ . '/../../vendor/autoload.php';

TinyOrm\DB::addConnection('default', [
    'read' => [
        'host' => [
            '127.0.0.1',
        ],
    ],
    'write' => [
        'host' => [
            '127.0.0.1',
        ],
    ],
    'driver' => 'mysql',
    'database' => 'bakeronline',
    'username' => 'root',
    'password' => 'root',
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
]);

class User extends TinyOrm\Model {
    static $_table = 'users';

    public function orders_Relation() {
        return $this->hasMany('Order', 'id', 'user_id');
    }
}
class Setting extends TinyOrm\Model {
    static $_table = 'settings';
}
class Order extends TinyOrm\Model {
    static $_table = 'orders';

    public function shop_Relation() {
        return $this->belongsTo('Shop', 'shop_id', 'id');
    }
}

class Shop extends TinyOrm\Model {
    static $_table = 'shops';

    public function categories_Relation() {
        return $this->belongsToMany('Category', 'id', 'category_shop', 'shop_id', 'category_id', 'id');
    }
}

class Category extends TinyOrm\Model {
    static $_table = 'categories';
}