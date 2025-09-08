<?php

namespace App\Services\Zatca\Certificate;

use Exception;
use Illuminate\Support\Facades\Log;

class CertificateGenerator extends BaseCertificateService
{
    /**
     * إنشاء شهادة جديدة
     */
    public function generate($store, $otp)
    {
        try {
            // التحقق من اسم المؤسسة
            $this->validateOrganizationName($store->common_name);

            // إنشاء CSR
            $CSR = $this->generateCSR($store);

            // إعداد ملفات الشهادة
            $store = $this->setupCertificateFiles($store, $CSR);

            // طلب التوافق
            $complianceResult = $this->requestCompliance($store, $otp);

            if (!$complianceResult['status']) {
                $errors = $this->handleComplianceErrors($complianceResult['data']);
                return ['status' => false, 'errors' => $errors];
            }

            $store = $complianceResult['settings'];
            $doneCount = 0;
            $templates = [];

            $complianceInv = $this->complianceInvoiceProcess($store);

            $doneCount = $complianceInv['done'];
            $templates = $complianceInv['templates'];
            $wants_review = $complianceInv['wants_review'];
            dd($complianceInv);

            if ($doneCount > 0 && $doneCount == sizeof($templates)) {
                $productionResult = $this->requestProductionCertificate($store, $otp);

                if (!$productionResult['status']) {
                    return ['status' => false, 'errors' => ['يوجد مشكلة في عملية الحصول على شهادة الإنتاج']];
                }
            } else {
                return ['status' => false, 'errors' => ["يوجد مشكلة في عملية التحقق من البيانات مع النماذج المطلوبة يرجي المحاولة مرة اخري"]];
            }

            return ['status' => true, 'data' => $productionResult['data']];
        } catch (Exception $e) {
            Log::error('Certificate Generation Error', ['store_id' => $store->id, 'message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return ['status' => false, 'errors' => [$e->getMessage()]];
        }
    }

    /**
     * معالجة فواتير التوافق
     */
    public function complianceInvoiceProcess($store)
    {
        $templateFiles = $this->getTemplates();
        $templates = $templateFiles['templates'];
        $templateData = $templateFiles['data'];

        $doneCount = 0;
        $dataCC = $wants_review = [];

        $store->temp_bill = true;
        $store->decode_cert = true;

        $client = new \GuzzleHttp\Client();

        foreach ($templates as $type => $postData) {
            $invoice = $this->replacePlaceholders($templateData, $postData);
            $this->initInvoice( $invoice );

            // $x = new \App\Services\Zatca\Certificate\ReportInvoiceToZatca($invoice, $store);
            // $toSendPostData = $x->ReportInvoice();

            $service = new \App\Services\Zatca\Invoice\ZatcaGenerator((object) $invoice, $store, true);
            $toSendPostData = $service->generate();

            try {
                $requestCurl = $client->request('POST', "{$store->base_url}/compliance/invoices", [
                    'json' => [
                        'invoiceHash' => $toSendPostData['invoiceHash'],
                        'invoice'     => $toSendPostData['invoice'],
                        'uuid'        => $toSendPostData['uuid'],
                    ],
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Accept-Language' => session('lang', 'ar'),
                        'Accept-Version' => 'V2',
                        'Accept' => 'application/json'
                    ],
                    'auth' => [
                        file_get_contents(storage_path($store->certificate)),
                        file_get_contents(storage_path($store->secret)),
                    ]
                ]);

                $responseInv = $requestCurl->getBody()->getContents();
                $responseInv = json_decode($responseInv);

                $docType = $postData['SET_DOC_TYPE'];
                $docType = $docType == 'simplified' ? 'reportingStatus' : 'clearanceStatus';

                if ($responseInv && isset($responseInv->{$docType}) && in_array($responseInv->{$docType}, ["REPORTED", "CLEARED"])) {
                    $doneCount++;
                    $dataCC[$type] = $responseInv->{$docType};
                } else {
                    $dataCC[$type] = 'error';
                }
            } catch (Exception $e) {
                $responseInv = $e->getResponse()->getBody()->getContents() ?? $e->getMessage();
                $responseInv = json_decode($responseInv);

                Log::info('compinv', [$responseInv]);
                $dataCC[$type] = 'error';
            }

            $check_result = $this->handelValidation($responseInv->validationResults);
            if ($check_result['check_result'] != 'PASSED') {
                $wants_review[$type] = $check_result;
            }
        }

        return [
            'done' => $doneCount,
            'templates' => $templates,
            'dataCC' => $dataCC,
            'wants_review' => $wants_review
        ];
    }

    /**
     * الحصول على القوالب
     */
    protected function getTemplates()
    {
        $templates = config('templates');

        $templateData = app_path('Services/Zatca/xml-templates/invoice.json');
        $templateData = file_get_contents($templateData);
        $templateData = json_decode($templateData, true);

        return [
            'templates' => $templates,
            'data' => $templateData
        ];
    }

    /**
     * معالجة التحقق من صحة البيانات
     */
    protected function handelValidation(object $responseContent)
    {
        $errors = $warnings = [];

        if (property_exists($responseContent, 'warningMessages') && $responseContent->warningMessages) {
            foreach ($responseContent->warningMessages as $warningMessage) {
                $warnings[$warningMessage->code] = $warningMessage->message;
            }
        }

        if (property_exists($responseContent, 'errorMessages') && $responseContent->errorMessages) {
            foreach ($responseContent->errorMessages as $errorMessage) {
                $errors[$errorMessage->code] = $errorMessage->message;
            }
        }

        $checkResult = count($errors) == 0 ? (count($warnings) == 0 ? 'PASSED' : 'WARNING') : 'ERROR';
        return ['errors' => $errors, 'warnings' => $warnings, 'check_result' => $checkResult];
    }

    /**
     * استبدال العناصر المؤقتة في البيانات
     */
    protected function replacePlaceholders($data, $values)
    {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->replacePlaceholders($value, $values);
            }
        } elseif ($data == 'SET_INVOICE_NUMBER') {
            $data = $values[ $data ] ?? null;
        } elseif (is_string($data)) {
            $data = isset($values[$data]) ? $values[$data] : $data;
        }

        return $data;
    }

    protected function initInvoice(array &$invoice): void
    {
        $invoice = json_decode(json_encode($invoice));
        $invoice->signing_time = \Carbon\Carbon::parse($invoice->issue_date . ' ' . $invoice->issue_time);
        $invoice->date = $invoice->issue_date . ' ' . $invoice->issue_time;
        $invoice->details = $invoice->items;

        foreach ($invoice->items as &$item) {
            $item->vats = collect($item->taxes);
        }
    }
}
