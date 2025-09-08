<?php

namespace App\Services\Zatca\CSR\Tags;

use App\Services\Zatca\CSR\Tag;

class CertificateSignature extends Tag
{
    public function __construct($value)
    {
        parent::__construct(9, $value);
    }
}
