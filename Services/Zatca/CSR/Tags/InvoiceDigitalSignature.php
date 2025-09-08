<?php

namespace App\Services\Zatca\CSR\Tags;

use App\Services\Zatca\CSR\Tag;

class InvoiceDigitalSignature extends Tag
{
    public function __construct($value)
    {
        parent::__construct(7, $value);
    }
}
