<?php

namespace App\Services\Zatca\Settings;

use Exception;
use Illuminate\Support\Facades\Cache;
use Modules\Rep\Entities\Store;
use Modules\Rep\Entities\ZatcaSetting;

class ZatcaSettingsService
{
    /**
     * الحصول على قائمة المتاجر مع إعدادات زاتكا
     */
    public function getStoresWithSettings()
    {
        return Store::select('stores.id', 'name', 'image', 'address', 'tax_number', 'commercial_register', 'zatca_settings.mode', 'zatca_settings.certificate')
                    ->where('for_damaged', false)
                    ->leftJoin('zatca_settings', function($join) {
                        $join->on('zatca_settings.store_id', '=', 'stores.id')->where('zatca_settings.shop_id', shopId());
                    })->get();
    }

    public function getStoreSettings(int $store_id)
    {
        return Cache::remember(shopId() . "_tax_integration_settings_{$store_id}", 60 * 60, function() use($store_id) {
            $store = Store::select('zatca_settings.*', 'stores.id', 'name', 'image', 'address', 'tax_number', 'commercial_register')
                            ->where('for_damaged', false)
                            ->leftJoin('zatca_settings', function($join) {
                                $join->on('zatca_settings.store_id', '=', 'stores.id')->where('zatca_settings.shop_id', shopId());
                            })->find($store_id);

            if (!$store) {
                throw new Exception('المتجر غير موجود');
            }

            $this->prepareStoreData($store);

            $this->setEnvironment($store);

            return $store;
        });
    }

    /**
     * إعداد بيانات المتجر
     */
    protected function prepareStoreData(Store &$store): void
    {
        preg_match_all('/\d/', $store->tax_number ?? '', $matches);
        $digits = array_slice($matches[0], 0, 10);

        $store->organization_unit_name = implode('', $digits);
        $store->egs_serial_number = "1-albadr|2-{$store->shop_id}|3-{$store->id}";
        $store->business_category = "general sales";
        $store->store_id = $store->id;
        $store->common_name = $store->name;
    }

    /**
     * إعداد البيئة والـ URL
     */
    protected function setEnvironment(Store &$store): void
    {
        if ($store->mode == 'production') { // LIVE MODE
            $store->env = "production";
            $store->base_url = 'https://gw-fatoora.zatca.gov.sa/e-invoicing/core';
        } else if ($store->mode == 'simulation') { // SIMULATION MODE
            $store->env = "simulation";
            $store->base_url = 'https://gw-fatoora.zatca.gov.sa/e-invoicing/simulation';
        } else { // DEVELOPER MODE
            $store->env = "sandbox";
            $store->base_url = 'https://gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal';
            $store->tax_number = '399999999900003';
        }

        $store->version = 'V2';
    }

    /**
     * الحصول على إعدادات زاتكا فقط
     */
    public function getZatcaSettings(int $store_id)
    {
        return ZatcaSetting::where('store_id', $store_id)->where('shop_id', shopId())->first();
    }

    /**
     * التحقق من وجود إعدادات زاتكا
     */
    public function hasZatcaSettings(int $store_id): bool
    {
        return ZatcaSetting::where('store_id', $store_id)->where('shop_id', shopId())->exists();
    }

    /**
     * التحقق من صحة الشهادة
     */
    public function validateCertificate(int $store_id)
    {
        $zatcaSetting = $this->getZatcaSettings($store_id);

        if (!$zatcaSetting || !$zatcaSetting->certificate) {
            return [
                'status' => false,
                'errors' => ['الشهادة غير موجودة']
            ];
        }

        $certificatePath = storage_path($zatcaSetting->certificate);
        if (!file_exists($certificatePath)) {
            return [
                'status' => false,
                'errors' => ['ملف الشهادة غير موجود']
            ];
        }

        return [
            'status' => true,
            'data' => [
                'certificate_exists' => true,
                'certificate_path' => $zatcaSetting->certificate,
                'created_at' => $zatcaSetting->created_at,
                'updated_at' => $zatcaSetting->updated_at
            ]
        ];
    }
}
