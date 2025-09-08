<?php

namespace App\Services\Zatca\Certificate;

use App\Services\Zatca\Settings\ZatcaSettingsService;

class CertificateService extends BaseCertificateService
{
    protected $settingsService;

    public function __construct()
    {
        $this->settingsService = new ZatcaSettingsService();
    }

    /**
     * إنشاء شهادة جديدة
     */
    public function generate($store, $otp)
    {
        return (new CertificateGenerator())->generate($store, $otp);
    }

    /**
     * تجديد شهادة موجودة
     */
    public function renew($store, $otp)
    {
        return (new CertificateRenewer())->renew($store, $otp);
    }

    /**
     * التحقق من صحة الشهادة
     */
    public function validate($store)
    {
        return $this->settingsService->validateCertificate($store->id);
    }
}
