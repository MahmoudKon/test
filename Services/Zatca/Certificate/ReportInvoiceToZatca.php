<?php

namespace App\Services\Zatca\Certificate;

use App\Services\Zatca\Invoice\ZatcaGenerator;

class ReportInvoiceToZatca
{
    private $invoice_builder;
    private $certificate;
    private $secret;
    private $csid;
    private $temp_bill;

    public function __construct(private $invoice, private $store)
    {
        $this->invoice_builder = new ZatcaGenerator($this->invoice, $this->store);
        $this->certificate = file_get_contents(storage_path($this->store->certificate));
        $this->secret = file_get_contents(storage_path($this->store->secret));
        $this->csid = file_get_contents(storage_path($this->store->csid));
        $this->temp_bill = $this->store->temp_bill ?? false;
    }

    /**
     *
     *  Report Invoice Start .
     *
     */
    public function ReportInvoice()
    {
        if ($this->temp_bill == true) {
            $post = [
                'invoiceHash' => $this->invoice_builder->GenerateInvoiceHash(),
                'uuid' => $this->invoice->uuid,
                'invoice' => $this->invoice_builder->GenerateInvoiceXmlEncoded(),
            ];

            $post['qr'] = $this->invoice_builder->GetQrCodeFromXml($post['invoice']);

            return $post;
        }

        return $this->invoice_builder->getXmlFiles();
    }
}
