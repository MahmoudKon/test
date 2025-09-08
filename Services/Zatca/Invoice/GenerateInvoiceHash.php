<?php
namespace App\Services\Zatca\Invoice;

class GenerateInvoiceHash
{
    private $xml;

    public function __construct($xml){
        $this->xml = $xml;
    }

    /**
     *
     * Generate Invoice Binary Hash Start .
     *
     */
    public function GenerateBinaryHash()
    {
        return hash('sha256',$this->xml,true);
    }

    /**
     *
     * Generate Invoice Binary Hash Encoded in Base64 Start .
     *
     */
    public function GenerateBinaryHashEncoded()
    {
        return base64_encode($this->GenerateBinaryHash());
    }
}
