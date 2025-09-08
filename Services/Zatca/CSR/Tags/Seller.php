<?php

namespace App\Services\Zatca\CSR\Tags;

use App\Services\Zatca\CSR\Tag;

class Seller extends Tag
{
    public function __construct($value)
    {
        parent::__construct(1, $value);
    }
}
