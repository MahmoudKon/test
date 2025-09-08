<?php

namespace App\Services\Zatca\Certificate;

use App\Services\Zatca\CSR\CSRRequest;
use App\Services\Zatca\CSR\GenerateCSR;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Rep\Entities\ZatcaSetting;

abstract class BaseCertificateService
{
    /**
     * إنشاء CSR (Certificate Signing Request)
     */
    protected function generateCSR($store)
    {
        $data = CSRRequest::make()
                ->setUID((string) $store->tax_number)
                ->setSerialNumber('albadr', (string) $store->shop_id, (string) $store->id)
                ->setCommonName((string) $store->common_name)
                ->setCountryName('SA')
                ->setOrganizationName((string) $store->name)
                ->setOrganizationalUnitName((string) ($store->name ?? $store->common_name))
                ->setRegisteredAddress((string) $store->address)
                ->setInvoiceType(true, true)
                ->setCurrentZatcaEnv($store->env)
                ->setBusinessCategory($store->business_category);

        return GenerateCSR::fromRequest($data)->initialize()->generate();
    }

    /**
     * الحصول على مسار ملفات الشهادة
     */
    protected function getCertificatePath($store_id)
    {
        $path = 'app' . DIRECTORY_SEPARATOR . 'shops' . DIRECTORY_SEPARATOR . shopId() . DIRECTORY_SEPARATOR . 'stores' . DIRECTORY_SEPARATOR . $store_id . DIRECTORY_SEPARATOR . 'cert-files';

        $tmpFile = storage_path($path . DIRECTORY_SEPARATOR . 'private.pem');
        $directory = dirname($tmpFile);

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        return $path;
    }

    /**
     * حفظ ملفات CSR
     */
    protected function saveCSRFiles($CSR, $csrPath, $pkPath)
    {
        openssl_pkey_export_to_file($CSR->getPrivateKey(), $pkPath);
        file_put_contents($csrPath, $CSR->getCsrContent());
    }

    /**
     * طلب التوافق مع زاتكا
     */
    protected function requestCompliance($store, $otp)
    {
        $client = new \GuzzleHttp\Client();

        try {
            $response = $client->request('POST', "{$store->base_url}/compliance", [
                'json' => [
                    'csr' => base64_encode(file_get_contents($store->csrPath)),
                ],
                'headers' => [
                    'Content-Type' => 'application/json',
                    'otp' => $otp,
                    'Accept-Version' => $store->version,
                    'Accept-Language' => app()->getLocale(),
                    'Accept' => 'application/json'
                ]
            ]);

            $responseData = json_decode($response->getBody()->getContents());
            $updatedSettings = $this->handleComplianceResponse($responseData, $store);

            return [
                'status' => true,
                'data' => $responseData,
                'settings' => $updatedSettings
            ];
        } catch (RequestException $e) {
            Log::error('Zatca Compliance Error', [
                'message' => $e->getMessage(),
                'response' => $e->getResponse()
            ]);

            return [
                'status' => false,
                'data' => $e->getResponse()
            ];
        }
    }

    /**
     * معالجة استجابة التوافق
     */
    protected function handleComplianceResponse($response, $store)
    {
        $path = $this->getCertificatePath($store->id);
        $directoryPath = storage_path($path);

        file_put_contents("{$directoryPath}/secret.key", $response->secret);
        file_put_contents("{$directoryPath}/certificate.key", $response->binarySecurityToken);
        file_put_contents("{$directoryPath}/request.key", $response->requestID);

        $store->certificate = "{$path}/certificate.key";
        $store->secret = "{$path}/secret.key";
        $store->csid = "{$path}/request.key";

        return $store;
    }

    /**
     * طلب شهادة الإنتاج
     */
    protected function requestProductionCertificate($store, $otp, $renew = false)
    {
        $client = new \GuzzleHttp\Client();

        try {
            if ( $renew ) {
                $json = ['csr' => base64_encode(file_get_contents($store->csrPath))];
                $auth = [
                    base64_encode( file_get_contents(storage_path($store->certificate)) ),
                    file_get_contents(storage_path($store->secret))
                ];
                $method = "PATCH";
            } else {
                $json = ['compliance_request_id' => file_get_contents(storage_path($store->csid))];
                $auth = [
                    file_get_contents(storage_path($store->certificate)),
                    file_get_contents(storage_path($store->secret))
                ];
                $method = "POST";
            }

            $response = $client->request($method, "{$store->base_url}/production/csids", [
                'json' => $json,
                'headers' => [
                    'Content-Type'   => 'application/json',
                    'Accept-Version' => $store->version,
                    'Accept'         => 'application/json',
                    'otp'            => $otp
                ],
                'auth' => $auth
            ]);

            $responseData = json_decode($response->getBody()->getContents());
            $updatedSettings = $this->handleProductionResponse($responseData, $store);

            return ['status' => true, 'data' => $updatedSettings];
        } catch (RequestException $e) {
            $response = $e->getResponse();
            $code = $response->getStatusCode();
            $responseData = json_decode($response->getBody()->getContents());

            if ($code == 428) {
                $updatedSettings = $this->handleComplianceResponse($responseData, $store);
                return ['status' => true, 'data' => $responseData, 'settings' => $updatedSettings];
            }

            Log::error('Zatca Production Error', ['message' => $e->getMessage(), 'response' => $responseData]);
            return ['status' => false, 'data' => $responseData];
        }
    }

    /**
     * معالجة استجابة شهادة الإنتاج
     */
    protected function handleProductionResponse($response, $store)
    {
        $path = $this->getCertificatePath($store->id);
        $directoryPath = storage_path($path);

        file_put_contents("{$directoryPath}/secret.key", $response->secret);
        file_put_contents("{$directoryPath}/certificate.key", base64_decode($response->binarySecurityToken));
        file_put_contents("{$directoryPath}/request.key", $response->requestID);

        $zatcaSetting = ZatcaSetting::where('store_id', $store->id)->where('shop_id', shopId())->first();

        $zatcaSetting->update([
            'otp'         => request()->otp,
            'private_key' => $store->private_key,
            'certificate' => "{$path}/certificate.key",
            'secret'      => "{$path}/secret.key",
            'csid'        => "{$path}/request.key",
            'csr_request' => "{$path}/csr.pem",
        ]);

        Cache::forget("{$store->shop_id}_tax_integration_settings_{$store->id}");
        return $zatcaSetting;
    }

    /**
     * معالجة أخطاء التوافق
     */
    protected function handleComplianceErrors($response)
    {
        if (is_null($response)) {
            return ['يوجد مشكلة بالشهادة الحالية'];
        }

        $errors = [];

        if (property_exists($response, 'errors')) {
            foreach ($response->errors as $error) {
                $errors[] = is_object($error) ? $error->message : $error;
            }
        } else {
            $responseData = $response->getBody()->getContents();
            $responseData = json_decode($responseData);
            if (isset($responseData->errors)) {
                $errors = collect($responseData->errors)->pluck('message')->toArray();
            }
        }

        return $errors;
    }

    /**
     * التحقق من صحة اسم المؤسسة
     */
    protected function validateOrganizationName($name)
    {
        if (strlen($name) > 115) {
            throw new \Exception('اسم المؤسسة يجب الا يتعدي عن 115 حرف');
        }
        return true;
    }

    /**
     * إعداد ملفات الشهادة
     */
    protected function setupCertificateFiles($store, $CSR)
    {
        $path = $this->getCertificatePath($store->id);
        $directoryPath = storage_path($path);

        $pkPath = $directoryPath . DIRECTORY_SEPARATOR . 'private.pem';
        $csrPath = $directoryPath . DIRECTORY_SEPARATOR . 'csr.pem';

        $this->saveCSRFiles($CSR, $csrPath, $pkPath);

        $store->private_key = $path . DIRECTORY_SEPARATOR . 'private.pem';
        $store->csrPath = $csrPath;

        return $store;
    }
}
