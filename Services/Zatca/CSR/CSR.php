<?php

namespace App\Services\Zatca\CSR;

class CSR
{
    private $csrContent;

    public $privateKey;

    public function __construct(string $csrContent, $privateKey)
    {
        $this->csrContent = $csrContent;
        $this->privateKey = $privateKey;
    }


    public function getCsrContent(): string
    {
        return $this->csrContent;
    }

    public function getPrivateKey()
    {
        return $this->privateKey;
    }

}
