<?php

namespace App\Services\Zatca\CSR\Tags;

use App\Services\Zatca\CSR\Tag;

class InvoiceTotalAmount extends Tag
{
    public function __construct($value)
    {
        parent::__construct(4, $value);
    }
}
