<?php

namespace App\Services\Zatca\CSR\Tags;

use App\Services\Zatca\CSR\Tag;

class PublicKey extends Tag
{
    public function __construct($value)
    {
        parent::__construct(8, $value);
    }
}
