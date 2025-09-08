<?php
namespace App\Services\Zatca\Invoice;

use phpseclib3\File\X509;

class Cert509XParser
{
    public $taxPayerConfig;
    public $certificate;

    public function __construct($taxPayerConfig)
    {
        $this->taxPayerConfig = $taxPayerConfig;
        if($this->taxPayerConfig->decode_cert == true){
            $this->certificate = base64_decode(file_get_contents(storage_path($this->taxPayerConfig->certificate)));
        }else{
            $this->certificate = file_get_contents(storage_path($this->taxPayerConfig->certificate));
        }
    }

    /**
     *
     *  Get Certificate With Headers And Footers Start .
     *
     */
    public function GetCertificate()
    {
        return "-----BEGIN CERTIFICATE-----\r\n" . $this->certificate . "\r\n-----END CERTIFICATE-----";
    }

    /**
     *
     *  Get Certificate Hash with Performed Hash Encoded Start .
     *
     */
    public function GetCertificateHashEncoded()
    {
        return base64_encode(hash('sha256',$this->certificate,false));
    }

    /**
     *
     *  Get Certificate After Extraction Start .
     *
     */
    public function GetCertificateExtracted()
    {
        $x509 = new X509();
        return $x509->loadX509($this->GetCertificate());
    }

    /**
     *
     *  Get Certificate Signature Start .
     *  Will be used in Tag 9
     *
     */
    public function GetCertificateSignature()
    {
        $certout = $this->GetCertificateExtracted();
        $signature = unpack('H*', $certout['signature'])['1'];
        return pack('H*', substr($signature,2));
    }

    /**
     *
     *  Get Certificate ECDSA Public key Start .
     *  Will be used in Tag 8
     *
     */
    public function GetCertificateECDSA()
    {
        $x509 = new X509();
        $cert_count = $x509->loadX509($this->GetCertificate());
        $public_key = $x509->getPublicKey();

        $get_public = str_replace("-----BEGIN PUBLIC KEY-----","",$public_key);
        $get_public = str_replace("------END PUBLIC KEY-----","",$get_public);
        $get_public = str_replace("-----END PUBLIC KEY-----","",$get_public);
        $get_public = str_replace("\r\n","",$get_public);
        $get_public = trim(rtrim($get_public));
        return base64_decode($get_public);
    }

    /**
     *
     *  Get Certificate Issuer Name Start .
     *
     */
    public function GetIssuerName()
    {
        $x509 = new X509();
        $cert_out = $x509->loadX509($this->GetCertificate());
        $issuer_names = [];
        $issuer_info = $x509->getIssuerDN(X509::DN_OPENSSL);
        foreach($issuer_info as $key_parent=>$string_row){
            if($key_parent == '0.9.2342.19200300.100.1.25'){
                foreach($string_row as $string){
                    $issuer_names[] =  'DC=' . $string;
                }
            }
            if($key_parent == 'CN'){
                $issuer_names[] =  'CN=' . $string_row;
            }
        }
        return implode(', ',array_reverse($issuer_names));
    }

    /**
     *
     *  Get Certificate Issuer Serial Number Start .
     *
     */
    public function GetIssuerSerialNumber()
    {
        $x509 = new X509();
        $cert_out = $x509->loadX509($this->GetCertificate());
        return $cert_out['tbsCertificate']['serialNumber']->toString();
    }
}
