<?php

namespace Modules\Rep\Versions\V1\S1\Http\Controllers\TaxIntegration;

use Modules\Rep\Versions\V1\S1\Http\Controllers\BasicApiController;
use Illuminate\Http\Request;
use Modules\Rep\Versions\V1\S1\Http\Services\TaxIntegration\SettingService;
use App\Services\Zatca\Certificate\CertificateService;
use App\Services\Zatca\Invoice\InvoiceSubmitService;
use App\Services\Zatca\Settings\ZatcaSettingsService;
use Modules\Rep\Entities\ZatcaResult;
use Carbon\Carbon;
use Exception;
use Modules\Rep\Entities\Transaction;

class TaxIntegrationController extends BasicApiController
{
    protected ZatcaSettingsService $settingsService;

    public function __construct()
    {
        $this->settingsService = new ZatcaSettingsService();
    }

    public function stores()
    {
        return $this->sendResponse(result: ['data' => $this->settingsService->getStoresWithSettings()]);
    }

    public function settings($store_id)
    {
        return $this->sendResponse(result: ['data' => $this->settingsService->getStoreSettings($store_id)]);
    }

    public function store($store_id, Request $request)
    {
        $row = new SettingService($store_id, $request->all());

        if ($row->errors()) {
            return $this->sendError('', $row->errors(), $row->code());
        }

        return $this->sendResponse(trans('tax-integration.settings.updated'), ['data' => $row->success()]);
    }

    public function certificate($store_id, Request $request)
    {
        if (!$request->input('otp')) {
            return $this->sendError('', [['otp' => ['يجب كتابة كود التحقق']]], 422);
        }

        try {
            $setting = $this->settingsService->getStoreSettings($store_id);
            $otp = $request->input('otp');
            $certificateService = new CertificateService();

            $isRenewal = isset($request->all()['renew']) && $request->all()['renew'] === 'yes';

            if ($isRenewal) {
                $result = $certificateService->renew($setting, $otp);
            } else {
                $result = $certificateService->generate($setting, $otp);
            }

            if ($result['status']) {
                $message = $isRenewal ? 'تم تجديد الشهادة بنجاح' : 'تم إنشاء الشهادة بنجاح';
                return $this->sendResponse($message, ['data' => $result['data']]);
            } else {
                return $this->sendError('فشل في العملية', [$result['errors']], 422);
            }
        } catch (Exception $e) {
            return $this->sendError("حدث خطأ أثناء معالجة الطلب | {$e->getMessage()}", [$e->getMessage()], 500);
        }
    }

    public function submitInvoice($invoice_id)
    {
        try {
            $result = (new InvoiceSubmitService())->submit($invoice_id);

            if ($result['status']) {
                return $this->sendResponse( $result['message'] );
            } else {
                return $this->sendError( $result['message'], code: 500);
            }
        } catch (Exception $e) {
            return $this->sendError("حدث خطأ أثناء مزامنة الفاتورة | {$e->getMessage()}", [$e->getMessage()], code: 500);
        }
    }

    public function submitInvoices($type)
    {
        try {
            $ids = $this->getInvoicesQuery($type)->select('id')->pluck('id')->toArray();

            $counter = 0;
            $service = new InvoiceSubmitService();
            foreach($ids as $id) {
                $result = $service->submit($id);
                if ($result['status']) {
                    $counter ++;
                }
            }
            return $this->sendResponse( "$counter من أصل " . count($ids) . " تم مزامنة الفواتير بنجاح" );
        } catch (Exception $e) {
            return $this->sendError(
                "حدث خطأ أثناء مزامنة الفاتورة | {$e->getMessage()}",
                [$e->getMessage()],
                500
            );
        }
    }

    public function invoices($type)
    {
        try {
            $rows = $this->getInvoicesQuery($type)->with('client:id,name', 'store:id,name')
                        ->join('transaction_details', 'transactions.id', '=', 'transaction_details.transaction_id')
                        ->leftJoin('transaction_detail_vats', 'transaction_details.id', '=', 'transaction_detail_vats.transaction_detail_id')
                        ->selectRaw("SUM(transaction_detail_vats.value * transaction_details.quantity) as vats_sum_value")
                        ->groupBy('transactions.id')
                        ->paginate( limited() );

            return $this->sendResponse(result: ['data' => $rows]);
        } catch (Exception $e) {
            return $this->sendError("حدث خطأ في جلب الفواتير | {$e->getMessage()}", ['message' => $e->getMessage()], 500);
        }
    }

    private function getInvoicesQuery(?string $type = null, $settings = null)
    {
        $settings = $settings ?? $this->settingsService->getStoreSettings( storeId() );

        return Transaction::query()->select('transactions.transaction_type', 'transactions.invoice_net', 'transactions.date', 'transactions.client_id', 'transactions.store_id')
                    ->whereIn('transactions.transaction_type', [INVOICE_SALES, INVOICE_SALE_RETURNS])
                    ->where('transactions.invoice_net', '>', 0)
                    ->where(function($query) {
                        $query->where(function($query) {
                            $query->where('transactions.transaction_type', INVOICE_SALE_RETURNS)->whereHas('sale');
                        })
                        ->orWhere('transactions.transaction_type', INVOICE_SALES);
                    })
                    ->whereDate('transactions.date', '>=', $settings->sync_start_date ?? now())
                    ->when($type, function($query) use($type) {
                        $query->when($type == 'sync', function($query){
                            $query->whereDoesntHave('zatcaResult');
                        }, function($query){
                            $query->whereHas('zatcaResult')->whereDoesntHave('zatcaResult', fn($query) => $query->whereIn('status', ['PASS']));
                        });
                    })
                    ->orderBy('id', 'ASC');
    }

    public function statistics()
    {
        try {
            $settings = $this->settingsService->getStoreSettings( storeId() );

            $statistics = [
                'settings' => $settings,
                'last_sync_date' => $this->getLastSyncDate(),
                'invoices_need_review' => $this->getInvoicesQuery('review', $settings)->count(),
                'unsynchronized_invoices' => $this->getInvoicesQuery('sync', $settings)->count(),
            ];

            return $this->sendResponse(result: ['data' => $statistics]);
        } catch (Exception $e) {
            return $this->sendError("حدث خطأ في جلب الإحصائيات | {$e->getMessage()}", ['message' => $e->getMessage()], 500);
        }
    }

    private function getLastSyncDate()
    {
        $lastSync = ZatcaResult::select('updated_at')->orderBy('updated_at', 'desc')->value('updated_at');

        if ($lastSync) {
            return Carbon::parse($lastSync)->format('Y-m-d H:i:s');
        }

        return 'لا توجد مزامنة سابقة';
    }

    public function submitResult($invoice_id)
    {
        $row = ZatcaResult::where('transaction_id', $invoice_id)->with('transaction:id,transaction_number,transaction_type')->orderBy('updated_at', 'desc')->first();
        return $this->sendResponse(result: ['data' => $row]);
    }
}
