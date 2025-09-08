<?php

namespace App\Services\Zatca\Invoice;

use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use Modules\Rep\Entities\Transaction;
use Modules\Rep\Entities\ZatcaResult;
use App\Services\Zatca\Invoice\ZatcaGenerator;
use App\Services\Zatca\Settings\ZatcaSettingsService;
use Exception;

class InvoiceSubmitService
{
    /**
     * إرسال فاتورة إلى زاتكا
     */
    public function submit(int $invoice_id): array
    {
        try {
            $invoice = Transaction::select('transactions.*')->where('id', $invoice_id)->with([
                'client:id,tax_number,address,street_name,building_number,plot_identification,postal_number,city_name,name',
                'details.item:id,name',
                'details.vats',
                'sale:id'
            ])->first();

            $this->checkValidInvoice($invoice);

            $store = (new ZatcaSettingsService())->getStoreSettings($invoice->store_id);

            $this->hasValidCertificate($store);

            if ($invoice->date < $store->sync_start_date) {
                throw new Exception("تاريخ الفاتورة خارج نطاق صلاحية الشهادة", 500);
            }

            $store->temp_bill = true;
            $store->decode_cert = false;
            $xmlGenerator = new ZatcaGenerator($invoice, $store);
            $invoiceData = $xmlGenerator->generate();

            $result = $this->sendInvoiceToZatca($invoiceData, $store);

            $this->saveZatcaResult($invoice, $invoiceData, $result['response']);

            if (!$result['status']) {
                return ['status' => false, 'message' => 'يرجي مراجعة الفاتورة لوجود اخطاء في تفاصيلها'];
            }

            return ['status' => true, 'message' => 'تم إرسال الفاتورة إلى زاتكا بنجاح'];

        } catch (Exception $e) {
            Log::error('Zatca Invoice Submit Error', [
                'invoice_id' => $invoice_id,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'status' => false,
                'message' => $e->getMessage() ?? 'حدث خطأ أثناء إرسال الفاتورة إلى زاتكا',
                'error' => $e->getMessage(),
                'exception' => $e
            ];
        }
    }

    /**
     * التحقق من وجود شهادة صالحة
     */
    protected function checkValidInvoice($invoice): void
    {
        if (!$invoice) {
            throw new Exception("الفاتورة غير موجودة", 500);
        }

        if (!in_array($invoice->transaction_type, [INVOICE_SALES, INVOICE_SALE_RETURNS])) {
            throw new Exception("نوع الفاتورة غير صحيح", 500);
        }

        if (ZatcaResult::where('transaction_id', $invoice->id)->where('status', 'PASS')->exists()) {
            throw new Exception("تم إرسال هذه الفاتورة إلى زاتكا مسبقاً", 500);
        }
    }

    /**
     * التحقق من وجود شهادة صالحة
     */
    protected function hasValidCertificate($store): void
    {
        if (!$store) {
            throw new Exception("إعدادات زاتكا غير موجودة لهذا المتجر", 501);
        }

        if (!$store->certificate || !file_exists(storage_path($store->certificate))) {
            throw new Exception("الشهادة غير موجودة، يرجى إنشاء شهادة أولاً", 501);
        }
    }

    /**
     * حفظ نتيجة زاتكا في قاعدة البيانات
     */
    protected function saveZatcaResult(Transaction $invoice, array $invoiceData, $result): void
    {
        ZatcaResult::updateOrCreate(['transaction_id' => $invoice->id], [
            'qr_code'       => $invoiceData['qr'] ?? null,
            'hash'          => $invoiceData['hash_xml'] ?? null,
            'xml'           => $invoiceData['sign_xml'] ?? null,
            'status'        => $result->validationResults->status,
            'invoice_type'  => $invoiceData['invoice_type'],
            'document_type' => $invoiceData['document_type'],
            'response'      => $result,
        ]);
    }

    /**
     * إرسال الفاتورة إلى زاتكا
     */
    protected function sendInvoiceToZatca($invoiceData, $store)
    {
        $client = new \GuzzleHttp\Client();

        $isStandardInvoice = $invoiceData['document_type'] === 'standard';
        $url = $isStandardInvoice ? "invoices/clearance/single" : "invoices/reporting/single";

        try {
            $response = $client->request('POST', "{$store->base_url}/{$url}", [
                'json' => [
                    'invoiceHash' => $invoiceData['invoiceHash'],
                    'invoice'     => $invoiceData['invoice'],
                    'uuid'        => $invoiceData['uuid'],
                ],
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept-Version' => $store->version,
                    'Accept' => 'application/json',
                    'Accept-Language' => session('lang', 'ar'),
                    'Clearance-Status' => $isStandardInvoice ? 1 : 0,
                ],
                'auth' => [
                    base64_encode(file_get_contents(storage_path($store->certificate))),
                    file_get_contents(storage_path($store->secret))
                ]
            ]);

            $status = true;
            $responseData = json_decode($response->getBody()->getContents());
        } catch (RequestException $e) {
            $status = false;
            $response = $e->getResponse();
            $responseData = json_decode($response->getBody()->getContents());

            if ( is_null($responseData) ) {
                throw new Exception("خطأ في الشهادة يرجي تجديدها", 500);
            }

            Log::error('Zatca Invoice Sync Error', [
                'message' => $e->getMessage(),
                'response' => $responseData,
                'invoice_data' => [
                    'uuid' => $invoiceData['uuid'],
                    'hash' => $invoiceData['invoiceHash'],
                    'document_type' => $invoiceData['document_type'],
                    'is_standard' => $isStandardInvoice,
                    'url' => $url
                ]
            ]);
        }

        foreach($responseData->validationResults?->warningMessages ?? [] as $index => $warningMessage) {
            if ($warningMessage->code == 'BR-KSA-98') {
                unset($responseData->validationResults->warningMessages[$index]);
            }
        }

        if ( count($responseData->validationResults?->warningMessages ?? []) == 0) {
            $status = true;
            $responseData->validationResults->status = 'PASS';
        }

        if ( count($responseData->validationResults?->errorMessages ?? []) > 0) {
            $status = false;
            $responseData->validationResults->status = 'ERROR';
        }

        return ['status' => $status, 'response' => $responseData];
    }

    /**
     * معالجة أخطاء الفواتير
     */
    protected function handleInvoiceErrors($response)
    {
        if (is_null($response)) {
            return ['يحدث خطأ ما عند الربط مع زاتكا'];
        }

        $messages = [];

        if (property_exists($response, 'validationResults')) {
            foreach ($response->validationResults as $validation_type => $errors) {
                $messages[$validation_type] = [];
                foreach ($errors as $error) {
                    $messages[$validation_type][] = $error->message;
                }
            }
        } else if (property_exists($response, 'errors')) {
            foreach ($response->errors as $error) {
                $messages[] = is_object($error) ? $error->message : $error;
            }
        }

        return $messages;
    }
}
