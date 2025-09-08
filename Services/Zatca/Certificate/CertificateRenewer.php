<?php

namespace App\Services\Zatca\Certificate;

use Illuminate\Support\Facades\Log;

class CertificateRenewer extends BaseCertificateService
{
    /**
     * تجديد شهادة موجودة
     */
    public function renew($store, $otp)
    {
        try {
            $this->validateOrganizationName($store->common_name);

            $CSR = $this->generateCSR($store);

            $store = $this->setupCertificateFiles($store, $CSR);

            $renewalResult = $this->requestProductionCertificate($store, $otp, true);

            if (!$renewalResult['status']) {
                $errors = $this->handleComplianceErrors($renewalResult['data']);
                return ['status' => false, 'errors' => $errors];
            }

            return ['status' => true, 'data' => $renewalResult['data']];
        } catch (\Exception $e) {
            Log::error('Certificate Renewal Error', ['store_id' => $store->id, 'message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return ['status' => false, 'errors' => [$e->getMessage()]];
        }
    }
}
