<?php

namespace App\Services\Zatca\Invoice;

use Modules\Rep\Entities\{Transaction, Store};
use Carbon\Carbon;
use Illuminate\Support\Str;
use App\Services\Zatca\Invoice\{BuildInvoiceLines, Cert509XParser, GenerateInvoiceHash, QRCode};
use DOMDocument;
use Exception;
use Illuminate\Support\Facades\DB;
use stdClass;

class ZatcaGenerator
{
    public $lines;
    public $certificate;
    public $digital_signature;
    public $signing_time;
    public $tempData = [];

    public function __construct(protected stdClass|Transaction $invoice, protected Store $store, bool $formated = false)
    {
        $this->signing_time = $invoice->signing_time ?? now();

        if ( !property_exists($this->invoice, 'issue_date') ) {
            $this->invoice->issue_date = Carbon::parse($this->invoice->date)->format('Y-m-d');
            $this->invoice->issue_time = Carbon::parse($this->invoice->date)->format('H:i:s');
        }

        $this->lines = new BuildInvoiceLines($formated ? $invoice : $this->convertTransactionToInvoiceFormat($invoice));

        $this->certificate = new Cert509XParser($this->convertStoreToSettingFormat($store));
    }

    /**
     * توليد جميع البيانات المطلوبة - النسخة المحدثة مع إصلاح مشاكل زاتكا
     */
    public function generate(): array
    {
        if ($this->store->temp_bill ?? false) {
            $post = [
                'invoiceHash'   => $this->GenerateInvoiceHash(),
                'uuid'          => $this->getUuid(),
                'invoice'       => $this->GenerateInvoiceXmlEncoded(),
                'hash_xml'      => $this->GenerateFullXml('hash'),
                'sign_xml'      => $this->GenerateFullXml('sign'),
                'invoice_type'  => $this->getInvoiceType(),
                'document_type' => $this->getDocumentType()
            ];

            $qrCode = $this->GetQrCodeFromXml( $post['invoice'] );

            $post['qr'] = $qrCode;
            return $post;
        }

        return $this->getXmlFiles();
    }

    /**
     * تحويل Transaction إلى تنسيق متوافق مع BuildInvoiceLines
     */
    private function convertTransactionToInvoiceFormat($transaction)
    {
        $invoiceObj = new stdClass();
        $invoiceObj->items = collect();

        foreach ($transaction->details as $index => $detail) {
            $item = new stdClass();
            $item->id = $index + 1;
            $item->qty = $detail->quantity;
            $item->sell_price = $detail->price;
            $item->name = $detail->item->name ?? 'Item';
            $item->vats = number_format($detail->vats->sum('value'), 2, '.', '');

            $item->taxes = collect();
            foreach ($detail->vats as $vat) {
                $item->taxes->push((object) [
                    'percentage' => number_format($vat->amount, 2, '.', ''),
                    'category'   => 'S',
                    'type'       => '',
                    'reason'     => '',
                ]);
            }

            $item->discounts = collect();
            if ($detail->discount > 0) {
                $item->discounts->push((object) ['amount' => $detail->discount, 'reason' => 'Discount']);
            }

            $invoiceObj->items->push($item);
        }

        $invoiceObj->invoice_number = $transaction->transaction_number;
        $invoiceObj->uuid = $this->getUuid();
        $invoiceObj->issue_date = Carbon::parse($transaction->date)->format('Y-m-d');
        $invoiceObj->issue_time = Carbon::parse($transaction->date)->format('H:i:s');
        $invoiceObj->invoice_type = $this->getInvoiceType();
        $invoiceObj->invoice_counter = $transaction->id;
        $invoiceObj->document_type = $this->getDocumentType();
        $invoiceObj->parentInvoice = $transaction->reference_id;

        $invoiceObj->client = $transaction->client;

        return $invoiceObj;
    }

    /**
     * تحويل Store إلى تنسيق متوافق مع Cert509XParser
     */
    private function convertStoreToSettingFormat($store)
    {
        $settingObj = new stdClass();
        $settingObj->name = $store->name;
        $settingObj->trn = $store->tax_number;
        $settingObj->crn = $store->commercial_register ?? '';
        $settingObj->street_name = $store->address ?? '';
        $settingObj->building_number = $store->building_number ?? '';
        $settingObj->plot_identification = $store->plot_identification ?? '';
        $settingObj->city = $store->city ?? '';
        $settingObj->postal_number = $store->postal_number ?? '';
        $settingObj->certificate = $store->certificate ?? '';
        $settingObj->secret = $store->secret ?? '';
        $settingObj->csid = $store->csid ?? '';
        $settingObj->private_key = $store->private_key ?? '';
        $settingObj->temp_bill = $store->temp_bill ?? false;
        $settingObj->decode_cert = $store->decode_cert ?? true;

        return $settingObj;
    }

    /**
     * الحصول على UUID
     */
    protected function getUuid(): string
    {
        if (empty($this->invoice->uuid)) {
            $this->invoice->uuid = Str::uuid()->toString();
            $this->invoice->save();
        }

        return $this->invoice->uuid;
    }

    /**
     * Build Billing Reference Xml
     */
    public function GetBillingReference()
    {
        $xmlPath = app_path('Services/Zatca/xml/xml_billing_reference.xml');
        if (!file_exists($xmlPath) || !$this->invoice->reference_id) {
            return '';
        }

        $xml_billing_reference = file_get_contents($xmlPath);
        $xml_billing_reference = str_replace("SET_INVOICE_NUMBER", $this->invoice->reference_id, $xml_billing_reference);
        return $xml_billing_reference;
    }

    /**
     * Get QR Code from XML
     */
    public function GetQrCodeFromXml($xml)
    {
        $xml_string = base64_decode($xml);
        $element = simplexml_load_string($xml_string);
        $element->registerXPathNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $element->registerXPathNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $result = $element->xpath('//cac:AdditionalDocumentReference[3]//cac:Attachment//cbc:EmbeddedDocumentBinaryObject')[0];

        if (($this->invoice->id ?? null) && ($this->invoice->shop_id ?? null)) {
            DB::table('transactions')->where([
                'id' => $this->invoice->id,
                'shop_id' => $this->invoice->shop_id
            ])->update(['qr_code' => (string) $result]);
        }

        return $result;
    }

    /**
     * Get Digital Signature
     */
    public function GetDigitalSignature()
    {
        $setting = $this->convertStoreToSettingFormat($this->store);
        $xml = $this->GenerateFullXml('hash');

        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->loadXML($xml);

        $ele = $dom->documentElement;
        $newXML = $ele->C14N();
        $default_xml_hash = hash('sha256', $newXML, true);

        $priv_key = $setting->private_key;

        if (!file_exists(storage_path($priv_key))) {
            throw new Exception("Private key file not found: " . storage_path($priv_key));
        }

        $priv_key = file_get_contents(storage_path($priv_key));
        $privateKey = openssl_pkey_get_private($priv_key);

        openssl_sign($default_xml_hash, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        $signature = base64_encode($signature);

        return $signature;
    }

    /**
     * Get PIH
     */
    public function GetPIH()
    {
        try {
            $previous_invoice = DB::table('zatca_results')->select('hash')
                                    ->where('shop_id', session('shop_id'))
                                    ->orderBy('id', 'DESC')
                                    ->first();

            if ($previous_invoice) {
                $hash = $previous_invoice->hash;
            } else {
                $hash = base64_encode(hash('sha256', '0', true));
            }
        } catch (Exception $e) {
            $hash = base64_encode(hash('sha256', '0', true));
        }

        $xmlPath = app_path('Services/Zatca/xml/previous_hash.xml');
        if (!file_exists($xmlPath)) {
            return '';
        }

        $previous_hash = file_get_contents($xmlPath);
        $previous_hash = str_replace("SET_PREVIOUS_INVOICE_HASH", $hash, $previous_hash);
        return $previous_hash;
    }

    /**
     * Generate Buyer Part
     */
    public function GetBuyer()
    {
        $xmlPath = app_path('Services/Zatca/xml/xml_client.xml');
        $client = $this->invoice->client ?? null;
        if (!file_exists($xmlPath) || !$client) {
            return '';
        }

        $xml_client = file_get_contents($xmlPath);
        $xml_client = str_replace("SET_CLIENT_VAT_NUMBER", $client->tax_number ?? '', $xml_client);
        $xml_client = str_replace("SCHEME_ID", $client->tax_number ? 'OTH' : 'OTH', $xml_client);
        $xml_client = str_replace("SET_CLIENT_STREET_NAME", $client->street_name ?? '', $xml_client);
        $xml_client = str_replace("SET_CLIENT_BUILDING_NUMBER", $client->building_number ?? '', $xml_client);
        $xml_client = str_replace("SET_CLIENT_PLOT_IDENTIFICATION", $client->plot_identification ?? '', $xml_client);
        $xml_client = str_replace("SET_CLIENT_SUB_DIVISION_NAME", $client->city_name ?? '', $xml_client);
        $xml_client = str_replace("SET_CLIENT_CITY_NAME", $client->city_name ?? '', $xml_client);
        $xml_client = str_replace("SET_CLIENT_POSTAL_ZONE", $client->postal_number ?? '', $xml_client);
        $xml_client = str_replace("SET_CLIENT_REGISTRATION_NAME", $client->name ?? '', $xml_client);
        $xml_client = str_replace("SET_COUNTRY", 'SA', $xml_client);

        return $xml_client;
    }

    /**
     * Generate Full XML
     */
    public function GenerateFullXml($type = 'hash')
    {
        $xmlPath = app_path('Services/Zatca/xml/xml_to_hash.xml');
        if ($type == 'sign') {
            $xmlPath = app_path('Services/Zatca/xml/xml_to_sign.xml');
        }

        if (!file_exists($xmlPath)) {
            throw new Exception("XML template file not found: " . $xmlPath);
        }

        $xml = file_get_contents($xmlPath);

        if ($type == 'sign') {
            $xml = str_replace("SET_UBL_Extensions", $this->GetUBLExtensions(), $xml);
        }

        $invoiceObj = $this->convertTransactionToInvoiceFormat($this->invoice);

        $xml = str_replace("SET_INVOICE_SERIAL_NUMBER", $invoiceObj->invoice_number, $xml);
        $xml = str_replace("SET_TERMINAL_UUID", $this->getUuid(), $xml);
        $xml = str_replace("SET_ISSUE_DATE", $invoiceObj->issue_date, $xml);
        $xml = str_replace("SET_ISSUE_TIME", $invoiceObj->issue_time, $xml);
        $xml = str_replace("SET_INVOICE_TYPE", $invoiceObj->invoice_type, $xml);
        $xml = str_replace("SET_PREVIOUS_INVOICE_HASH", $this->GetPIH(), $xml);

        // تحديد نوع المستند بناءً على نوع الفاتورة
        $documentType = $this->isSimplifiedInvoice() ? '0200000' : '0100000';
        $xml = str_replace("SET_DOCUMENT", $documentType, $xml);
        $xml = str_replace("SET_BILLING_REFERENCE", $this->GetBillingReference(), $xml);
        $xml = str_replace("SET_INVOICE_COUNTER_NUMBER", $invoiceObj->invoice_counter, $xml);

        // معلومات المتجر
        $xml = str_replace("SET_COMMERCIAL_REGISTRATION_NUMBER", $this->store->commercial_register ?? '', $xml);
        $xml = str_replace("SET_STREET_NAME", $this->store->address ?? '', $xml);
        $xml = str_replace("SET_BUILDING_NUMBER", $this->store->building_number ?? '', $xml);
        $xml = str_replace("SET_PLOT_IDENTIFICATION", $this->store->plot_identification ?? '', $xml);
        $xml = str_replace("SET_CITY_SUBDIVISION", $this->store->city ?? '', $xml);
        $xml = str_replace("SET_CITY", $this->store->city ?? '', $xml);
        $xml = str_replace("SET_POSTAL_NUMBER", $this->store->postal_number ?? '', $xml);
        $xml = str_replace("SET_VAT_NUMBER", $this->store->tax_number, $xml);
        $xml = str_replace("SET_VAT_NAME", $this->store->name, $xml);
        $xml = str_replace("SET_CLIENT", $this->GetBuyer(), $xml);

        if ($invoiceObj->invoice_type == 383 || $invoiceObj->invoice_type == 381) {
            $xmlReturnPath = app_path('Services/Zatca/xml/xml_return_reason.xml');
            if (file_exists($xmlReturnPath)) {
                $xml_return_reason = file_get_contents($xmlReturnPath);
            } else {
                $xml_return_reason = '';
            }
        } else {
            $xml_return_reason = '';
        }

        $xml = str_replace("SET_RETURN_REASON", $xml_return_reason, $xml);
        $xml = str_replace("SET_TAX_TOTALS", $this->lines->GenerateTaxTotalsXml(), $xml);
        $xml = str_replace("SET_LINE_EXTENSION_AMOUNT", number_format($this->lines->items_total, 2, '.', ''), $xml);
        $xml = str_replace("SET_EXCLUSIVE_AMOUNT", number_format($this->lines->lines_sub_total, 2, '.', ''), $xml);
        $xml = str_replace("SET_ALLOWANCE_AMOUNT", number_format($this->lines->lines_discount_total, 2, '.', ''), $xml);
        $xml = str_replace("SET_NET_TOTAL", number_format($this->lines->lines_net_total, 2, '.', ''), $xml);
        $xml = str_replace("SET_LINE_ITEMS", $this->lines->generated_lines_xml, $xml);
        $xml = str_replace("SET_INVOICE_ALLOWANCES", $this->lines->generated_invoice_allowance_charge, $xml);

        if ($type == 'sign') {
            $qr = $this->GenerateQrCode();
            $this->tempData['qr'] = $qr;
            $xml = str_replace("SET_QR_CODE_DATA", (string) $qr, $xml);
        }

        $xmlPath = storage_path("app/shops/{$this->store->shop_id}/stores/{$this->store->id}/files/" . date('Y') . '/' . date('Y-m') . '/' . date('Y-m-d') . "/temp-{$type}-{$this->invoice->id}.xml");
        $directory = dirname($xmlPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        file_put_contents($xmlPath, $xml);

        $this->tempData["{$type}_xml"] = $xml;

        return $xml;
    }

    /**
     * Generate Invoice Hash
     */
    public function GenerateInvoiceHash()
    {
        $new_obj = new GenerateInvoiceHash($this->GenerateFullXml('hash'));
        return $new_obj->GenerateBinaryHashEncoded();
    }

    /**
     * Generate Invoice XML Encoded
     */
    public function GenerateInvoiceXmlEncoded()
    {
        return base64_encode($this->GenerateFullXml('sign'));
    }

    /**
     * Generate QR Code
     */
    public function GenerateQrCode(): string
    {
        $data = [
            $this->store->name ?? '',
            $this->store->tax_number ?? '',
            (string)$this->invoice->issue_date . 'T' . (string)$this->invoice->issue_time,
            number_format($this->lines->lines_net_total, 2, '.', ''),
            number_format($this->lines->lines_tax_total, 2, '.', ''),
            $this->GenerateInvoiceHash(),
            $this->digital_signature ?? $this->GetDigitalSignature(),
            $this->certificate->GetCertificateECDSA(),
            $this->certificate->GetCertificateSignature(),
        ];

        $new_qr = new QRCode($data);
        $_qr = $new_qr->toBase64();
        $this->invoice->qr_generated = $_qr;
        return (string) $_qr;
    }

    /**
     * تحديد ما إذا كانت الفاتورة مبسطة
     */
    private function isSimplifiedInvoice()
    {
        return empty($this->invoice->client->tax_number);
    }

    /**
     * Get UBL Extensions
     */
    public function GetUBLExtensions()
    {
        $this->digital_signature = $this->GetDigitalSignature();

        $xmlPath = app_path('Services/Zatca/xml/xml_ubl_extensions.xml');
        if (!file_exists($xmlPath)) {
            return '';
        }

        $xml_ubl_extensions = file_get_contents($xmlPath);
        $ubl_xml = str_replace("SET_INVOICE_HASH", $this->GenerateInvoiceHash(), $xml_ubl_extensions);
        $ubl_xml = str_replace("SET_SIGNED_PROPERTIES_HASH", $this->GenerateSignedPropertiesHashEncoded(), $ubl_xml);
        $ubl_xml = str_replace("SET_DIGITAL_SIGNATURE", $this->digital_signature, $ubl_xml);
        $ubl_xml = str_replace("SET_CERTIFICATE_VALUE", $this->certificate->certificate, $ubl_xml);
        $ubl_xml = str_replace("SET_CERTIFICATE_SIGNED_PROPERTIES", $this->GenerateSignedProperties(), $ubl_xml);
        return $ubl_xml;
    }

    /**
     * Generate Signed Properties
     */
    public function GenerateSignedProperties()
    {
        $xmlPath = app_path('Services/Zatca/xml/xml_ubl_signed_properties.xml');
        if (!file_exists($xmlPath)) {
            return '';
        }

        $xml_ubl_signed_properties = file_get_contents($xmlPath);
        $xml_ubl_certificate_signed_properties = str_replace("SET_SIGN_TIMESTAMP", Carbon::parse($this->signing_time)->toIso8601ZuluString(), $xml_ubl_signed_properties);
        $xml_ubl_certificate_signed_properties = str_replace("SET_CERTIFICATE_HASH", $this->certificate->GetCertificateHashEncoded(), $xml_ubl_certificate_signed_properties);
        $xml_ubl_certificate_signed_properties = str_replace("SET_CERTIFICATE_ISSUER", $this->certificate->GetIssuerName(), $xml_ubl_certificate_signed_properties);
        $xml_ubl_certificate_signed_properties = str_replace("SET_CERTIFICATE_SERIAL_NUMBER", $this->certificate->GetIssuerSerialNumber(), $xml_ubl_certificate_signed_properties);
        return $xml_ubl_certificate_signed_properties;
    }

    /**
     * Generate Signed Properties Hash Encoded
     */
    public function GenerateSignedPropertiesHashEncoded()
    {
        $signed_properties = $this->GenerateSignedProperties();
        $signed_properties = hash('sha256', $signed_properties, false);
        $base64Data = base64_encode($signed_properties);
        return $base64Data;
    }

    /**
     * تحديد نوع الفاتورة
     */
    protected function getInvoiceType(): int
    {
        $invoice_type = 388;
        if ($this->invoice->transaction_type == INVOICE_SALES && $this->invoice->reference_id) {
            $invoice_type = 381; // SALES FOR ANOTHER INVOICE
        } else if ($this->invoice->transaction_type == INVOICE_SALE_RETURNS) {
            $invoice_type = 383; // RETURN
        } else {
            $invoice_type = 388; // SALES
        }

        return $invoice_type;
    }

    protected function getDocumentType(): string
    {
        return $this->isSimplifiedInvoice() ? 'simplified' : 'standard';
    }

    /**
     * Get XML files
     */
    public function getXmlFiles()
    {
        return [
            'hash_xml'      => $this->GenerateFullXml('hash'),
            'sign_xml'      => $this->GenerateFullXml('sign'),
            'invoice_hash'  => $this->GenerateInvoiceHash(),
            'qr_code'       => $this->GenerateQrCode(),
            'encoded_xml'   => $this->GenerateInvoiceXmlEncoded(),
            'is_simplified' => $this->isSimplifiedInvoice(),
            'document_type' => $this->getDocumentType(),
            'invoice_type'  => $this->getInvoiceType()
        ];
    }
}
