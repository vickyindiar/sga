<?php

namespace App\Http\Controllers;

use DateTime;
use Illuminate\Http\Request;
use Google\Ads\GoogleAds\Examples\Utils\ArgumentNames;
use Google\Ads\GoogleAds\Examples\Utils\ArgumentParser;
use Google\Ads\GoogleAds\Lib\V6\GoogleAdsClient;
use Google\Ads\GoogleAds\Lib\V6\GoogleAdsClientBuilder;
use Google\Ads\GoogleAds\Lib\V6\GoogleAdsException;
use Google\Ads\GoogleAds\Lib\OAuth2TokenBuilder;
use Google\Ads\GoogleAds\V6\Enums\BillingSetupStatusEnum\BillingSetupStatus;
use Google\Ads\GoogleAds\V6\Errors\GoogleAdsError;
use Google\Ads\GoogleAds\V6\Services\GoogleAdsRow;
use Google\Ads\GoogleAds\Examples\Utils\Helper;
use Google\Ads\GoogleAds\Util\V6\ResourceNames;
use Google\Ads\GoogleAds\V6\Enums\InvoiceTypeEnum\InvoiceType;
use Google\Ads\GoogleAds\V6\Enums\MonthOfYearEnum\MonthOfYear;
use Google\Ads\GoogleAds\V6\Resources\Invoice;
use Google\Ads\GoogleAds\V6\Resources\Invoice\AccountBudgetSummary;
use Google\ApiCore\ApiException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

use Spatie\PdfToText\Pdf;

class InvoiceController extends Controller
{
    private const PAGE_SIZE = 1000;

    public function fetchBillingSetup(Request $request){
        $result = [];

        $oAuth2Credential =
        (new OAuth2TokenBuilder())->withClientId($request->input('cI'))
        ->withClientSecret($request->input('cS'))
        ->withRefreshToken($request->input('refreshToken'))
        ->build();

        $googleAdsClient = (new GoogleAdsClientBuilder())
        ->withLoginCustomerId($request->input('loginCustomerId'))
        ->withDeveloperToken($request->input('devT'))
        ->withOAuth2Credential($oAuth2Credential)
        ->build();

        $googleAdsServiceClient = $googleAdsClient->getGoogleAdsServiceClient();
        // Creates a query that retrieves the billing setups.
        $query = 'SELECT billing_setup.id, '
        . '  billing_setup.status, '
        . '  billing_setup.payments_account_info.payments_account_id, '
        . '  billing_setup.payments_account_info.payments_account_name, '
        . '  billing_setup.payments_account_info.payments_profile_id, '
        . '  billing_setup.payments_account_info.payments_profile_name, '
        . '  billing_setup.payments_account_info.secondary_payments_profile_id '
        . 'FROM billing_setup';

        $response = $googleAdsServiceClient->search($request->input('customerId'), $query);

        foreach ($response->iterateAllElements() as $googleAdsRow) {
            /** @var GoogleAdsRow $googleAdsRow */
            $paymentAccountInfo = $googleAdsRow->getBillingSetup()->getPaymentsAccountInfo();
            if (is_null($paymentAccountInfo)) { continue; }
            $billDetail = [
                'id'                    => $googleAdsRow->getBillingSetup()->getId(),
                'status'                => BillingSetupStatus::name($googleAdsRow->getBillingSetup()->getStatus()),
                'payments_account_id'   => $paymentAccountInfo->getPaymentsAccountId(),
                'payments_account_name' => $paymentAccountInfo->getPaymentsAccountName(),
                'payments_profile_id'   => $paymentAccountInfo->getPaymentsProfileId(),
                'payments_profile_name' => $paymentAccountInfo->getPaymentsProfileName(),
                'sec_pay_profile_id '   => $paymentAccountInfo->getSecondaryPaymentsProfileId() ? $paymentAccountInfo->getSecondaryPaymentsProfileId() : 'None',
            ];
            array_push($result, $billDetail);
        }
       return response()->json($result);
    }

    public function fetchInvoice(Request $request){
        $result = [];

        $oAuth2Credential =
        (new OAuth2TokenBuilder())->withClientId($request->input('cI'))
        ->withClientSecret($request->input('cS'))
        ->withRefreshToken($request->input('refreshToken'))
        ->build();

        $googleAdsClient = (new GoogleAdsClientBuilder())
        ->withOAuth2Credential($oAuth2Credential)
        ->withLoginCustomerId($request->input('loginCustomerId'))
        ->withDeveloperToken($request->input('devT'))
        ->build();

        $customerId = $request->input('customerId');
        $arrBilling = $this->getBillingSetup($googleAdsClient, $customerId);
        $billingSetupId = '';
        if(count($arrBilling) > 0){
            $billingSetupId =  $arrBilling[0]['id'];
        }
        else{
            $result = [
                'error' => true,
                'message' => 'billing setup not found'
            ];
            return response()->json($result);
        }

        $cPeriod = new DateTime($request->input('period'));

        for ($i=0; $i < 3; $i++) {
            switch ($i) {
                case 0: $period = clone $cPeriod; date_modify($period, 'first day of previous month'); break;
                case 1: $period = clone $cPeriod; break;
                case 2: $period = clone $cPeriod; date_modify($period, 'first day of next month'); break;
                default: $period = clone $cPeriod; break;
            };

            $response = $googleAdsClient->getInvoiceServiceClient()->listInvoices(
                $customerId,
                ResourceNames::forBillingSetup($customerId, $billingSetupId),
                $period->format('Y'),
               // date('Y', $period),
               MonthOfYear::value(strtoupper($period->format('F')))
                //MonthOfYear::value(strtoupper(date('F', $period)))
            );
            $accessToken = $googleAdsClient->getOAuth2Credential()->getLastReceivedToken()['access_token'];
            foreach ($response->getInvoices() as $invoice) {
                /** @var Invoice $invoice */
                $row = [];
                $invDetail =  [
                    'resource_name' => $invoice->getResourceName(),
                    'invoice_number' => $invoice->getId(),
                    'type' => InvoiceType::name($invoice->getType()),
                    'billing_setup_id' => $invoice->getBillingSetup(),
                    'billing_account' => $invoice->getPaymentsAccountId(),
                    'billing_id' => $invoice->getPaymentsProfileId(),
                    'invoice_date' => $invoice->getIssueDate(),
                    'due_date' => $invoice->getDueDate(),
                    'currency_code' => $invoice->getCurrencyCode(),
                    'from' => $invoice->getServiceDateRange()->getStartDate(),
                    'to' => $invoice->getServiceDateRange()->getEndDate(),
                    'adjustments_subtotal' => Helper::microToBase($invoice->getAdjustmentsSubtotalAmountMicros()),
                    'adjustments_tax' => Helper::microToBase($invoice->getAdjustmentsTaxAmountMicros()),
                    'adjustments_total' => Helper::microToBase($invoice->getAdjustmentsTotalAmountMicros()),
                    'regulatory_costs_subtotal' => Helper::microToBase($invoice->getRegulatoryCostsSubtotalAmountMicros()),
                    'regulatory_costs_tax' => Helper::microToBase($invoice->getRegulatoryCostsTaxAmountMicros()),
                    'regulatory_costs_total' => Helper::microToBase($invoice->getRegulatoryCostsTotalAmountMicros()),
                    'replaced _invoices' => $invoice->getReplacedInvoices() ? implode( "', '", iterator_to_array($invoice->getReplacedInvoices()->getIterator()) ) : 'none',
                    'invoice_amounts_subtota' => Helper::microToBase($invoice->getSubtotalAmountMicros()),
                    'invoice_amounts_tax' => Helper::microToBase($invoice->getTaxAmountMicros()),
                    'invoice_amounts_total' => Helper::microToBase($invoice->getTotalAmountMicros()),
                    'corrected_invoice' => $invoice->getCorrectedInvoice() ?: 'none',
                    'pdf_url' => $invoice->getPdfUrl()
                ];
                foreach ($invoice->getAccountBudgetSummaries() as $accountBudgetSummary) {
                    /** @var AccountBudgetSummary $accountBudgetSummary */
                    $abs = [
                        'account_budget_id' => $accountBudgetSummary->getAccountBudget(),
                        'account_budget_name' => $accountBudgetSummary->getAccountBudgetName() ?: 'none',
                        'account_name' => $accountBudgetSummary->getCustomer(),
                        'purchase_order' => $accountBudgetSummary->getCustomerDescriptiveName() ?: 'none',
                        'inclusive' => $accountBudgetSummary->getPurchaseOrderNumber() ?: 'none',
                        'billing_activity_from' => $accountBudgetSummary->getBillableActivityDateRange()->getStartDate(),
                        'biliing_activity_to' => $accountBudgetSummary->getBillableActivityDateRange()->getEndDate(),
                        'account_budget_subtotal' => Helper::microToBase($accountBudgetSummary->getSubtotalAmountMicros()),
                        'account_budget_tax' => Helper::microToBase($accountBudgetSummary->getTaxAmountMicros()),
                        'account_budget_total' => Helper::microToBase($accountBudgetSummary->getTotalAmountMicros())
                    ];
                }
                $fileRequest = Http::withHeaders([
                    'Authorization'=> 'Bearer '.$accessToken,
                    'Content-type'=>'application/pdf'
                ])
                ->get($invDetail['pdf_url']);

                $fileName = $invDetail['invoice_number'];
                $file = $fileRequest->getBody();

                $invDetails = $this->extractInvoice($file, $fileName);

                $row = $invDetail + $abs;
                $row['details'] = $invDetails;
                array_push($result, $row);
            }
        }
      return response()->json($result);
    }

    public function extractInvoice($file, $fileName){
        //$fileName = '3836023202';
        Storage::put($fileName.'.pdf', $file);
        $pathFile = Storage::path($fileName.'.pdf');
        $textFile = Pdf::getText($pathFile, Storage::path('lib').'/pdftotext', ['table']);//Storage::path('').'pdftotext'
        file_put_contents(storage_path('app').'/'.$fileName.'.txt' , $textFile);
        //Storage::delete($fileName.'.pdf');
        //Storage::delete($fileName.'.txt');

        $firstIndexCPage = 0;
        $countCharTextFile = strlen($textFile);
        $invData = [];
        for ($i=1; $i < 5; $i++) {

            $campaignName = $qty = $units = $amounts = [];

            if($firstIndexCPage >= $countCharTextFile){ break; }

            $lastIndexCPage = strpos($textFile, 'Page '.$i.' of ') + strlen('page x of x');
            $textCPage = substr($textFile, $firstIndexCPage, $lastIndexCPage);

            $descIndex = strpos($textCPage, 'Description');
            if(!$descIndex){ $firstIndexCPage = $lastIndexCPage; continue;  }

            $subTotal = strpos($textCPage, 'Subtotal in IDR');

            $detailSection = substr($textCPage, $descIndex, $subTotal - $descIndex);
            $row = preg_split('/\r\n|\r|\n/', $detailSection);

            for ($r=0; $r < count($row) - 1; $r++) {
                $totalCharRow = strlen($row[$r]);
                if($r == count($row) -1){break;}
                if($r == 0){
                    $descIndex = 0;
                    $qtyIndex = strpos($row[$r], 'Quantity');
                    $unitsIndex = strpos($row[$r], 'Units');
                    $amountIndex = strpos($row[$r], 'Amount(IDR)');
                    $lastIndexHeaderRow = $totalCharRow;
                    continue;
                }

                //==================------invoice column==============================//
                // description - - - - Quantity - - - - Unit - - - - Amount(IDR)      //
                //====================================================================//
                $descValue = trim(substr($row[$r], $descIndex, $qtyIndex - 1));

                $exDescValue = substr($row[$r], $qtyIndex - 1, $totalCharRow - $descIndex);

                if($exDescValue){
                    $arrExDescValue = explode(' ', $exDescValue); //get value quantity/units and amount by split method
                    $arrExDescValue = array_filter($arrExDescValue, function($v){ return $v !== ''; }); // remove empty value array
                    $arrExDescValue = array_values($arrExDescValue); // reindex array
                }

                $qtyValue = trim(substr($row[$r], $qtyIndex, $unitsIndex - $qtyIndex - 1));
                if(str_contains($qtyValue, ' ')){ $qtyValue = $arrExDescValue[0]; }

                $unitsValue = trim(substr($row[$r], $unitsIndex, $amountIndex - $unitsIndex - 1));
                if(str_contains($unitsValue, ' ')){ $unitsValue = $arrExDescValue[1]; }

                $amountValue = trim(substr($row[$r], $amountIndex, $totalCharRow - $amountIndex));
                if( (str_contains($amountValue, ' ') && !str_contains($amountValue, 'IDR') ) ||
                    (($amountValue !== '') && ($lastIndexHeaderRow - $totalCharRow > 3))
                  ){ $amountValue = $arrExDescValue[2]; }

                //======= fix one campaign name has 2 rows
                 if ($unitsValue == '' && $qtyValue == '' && $amountValue == '' && $descValue !== ''){
                     $campaignName[count($campaignName) - 1] = $campaignName[count($campaignName)- 1].$descValue;
                 }
                //======

                if ( $descValue !== '' && $unitsValue !== '' ) { array_push($campaignName, $descValue); }
                if ( $qtyValue !== '') array_push($qty, $qtyValue);
                if ( $unitsValue !== '' ) array_push($units, $unitsValue);
                if ( $amountValue !== '' ) {
                    $amountValue = str_replace(',', '', $amountValue);
                    $amountValue = str_replace('.00', '', $amountValue);
                    array_push($amounts, $amountValue);
                }
            }

            for ($j=0; $j < count($campaignName); $j++) {
                array_push($invData, [
                    'Campaign' => $campaignName[$j],
                    'Quantity' => $qty[$j],
                    'Units' => $units[$j],
                    'Amount' => $amounts[$j]
                ]);
            }
        }
        return $invData;
    }

    public function getBillingSetup($googleAdsClient, $customerId){
        $result = [];

        $googleAdsServiceClient = $googleAdsClient->getGoogleAdsServiceClient();
        // Creates a query that retrieves the billing setups.
        $query = 'SELECT billing_setup.id, '
        . '  billing_setup.status, '
        . '  billing_setup.payments_account_info.payments_account_id, '
        . '  billing_setup.payments_account_info.payments_account_name, '
        . '  billing_setup.payments_account_info.payments_profile_id, '
        . '  billing_setup.payments_account_info.payments_profile_name, '
        . '  billing_setup.payments_account_info.secondary_payments_profile_id '
        . 'FROM billing_setup';

        $response = $googleAdsServiceClient->search($customerId, $query);

        foreach ($response->iterateAllElements() as $googleAdsRow) {
            /** @var GoogleAdsRow $googleAdsRow */
            $paymentAccountInfo = $googleAdsRow->getBillingSetup()->getPaymentsAccountInfo();
            if (is_null($paymentAccountInfo)) { continue; }
            $billDetail = [
                'id'                    => $googleAdsRow->getBillingSetup()->getId(),
                'status'                => BillingSetupStatus::name($googleAdsRow->getBillingSetup()->getStatus()),
                'payments_account_id'   => $paymentAccountInfo->getPaymentsAccountId(),
                'payments_account_name' => $paymentAccountInfo->getPaymentsAccountName(),
                'payments_profile_id'   => $paymentAccountInfo->getPaymentsProfileId(),
                'payments_profile_name' => $paymentAccountInfo->getPaymentsProfileName(),
                'sec_pay_profile_id '   => $paymentAccountInfo->getSecondaryPaymentsProfileId() ? $paymentAccountInfo->getSecondaryPaymentsProfileId() : 'None',
            ];
            array_push($result, $billDetail);
        }
       return $result;
    }

    public function getCollectionInvoice($googleAdsClient, $customerId, $startDate, $endDate){
        $result = [];

        $arrBilling = $this->getBillingSetup($googleAdsClient, $customerId);
        $billingSetupId = '';
        if(count($arrBilling) > 0){
            $billingSetupId =  $arrBilling[0]['id'];
        }
        else{
            $result = [
                'error' => true,
                'message' => 'billing setup not found'
            ];
            return response()->json($result);
        }

        $firstDate = new DateTime($startDate);
        $firstDate->modify('first day of -1 month');

        $lastDate = new DateTime($endDate);
        $lastDate->modify('first day of +1 month');

        $period = clone $firstDate;
        for ($i=0;  $period <= $lastDate ; $i++) {

            $response = $googleAdsClient->getInvoiceServiceClient()->listInvoices(
                $customerId,
                ResourceNames::forBillingSetup($customerId, $billingSetupId),
                $period->format('Y'),
               // date('Y', $period),
               MonthOfYear::value(strtoupper($period->format('F')))
                //MonthOfYear::value(strtoupper(date('F', $period)))
            );
            $accessToken = $googleAdsClient->getOAuth2Credential()->getLastReceivedToken()['access_token'];
            foreach ($response->getInvoices() as $invoice) {
                /** @var Invoice $invoice */
                $row = [];
                $invDetail =  [
                    'resource_name' => $invoice->getResourceName(),
                    'invoice_number' => $invoice->getId(),
                    'type' => InvoiceType::name($invoice->getType()),
                    'billing_setup_id' => $invoice->getBillingSetup(),
                    'billing_account' => $invoice->getPaymentsAccountId(),
                    'billing_id' => $invoice->getPaymentsProfileId(),
                    'invoice_date' => $invoice->getIssueDate(),
                    'due_date' => $invoice->getDueDate(),
                    'currency_code' => $invoice->getCurrencyCode(),
                    'from' => $invoice->getServiceDateRange()->getStartDate(),
                    'to' => $invoice->getServiceDateRange()->getEndDate(),
                    'adjustments_subtotal' => Helper::microToBase($invoice->getAdjustmentsSubtotalAmountMicros()),
                    'adjustments_tax' => Helper::microToBase($invoice->getAdjustmentsTaxAmountMicros()),
                    'adjustments_total' => Helper::microToBase($invoice->getAdjustmentsTotalAmountMicros()),
                    'regulatory_costs_subtotal' => Helper::microToBase($invoice->getRegulatoryCostsSubtotalAmountMicros()),
                    'regulatory_costs_tax' => Helper::microToBase($invoice->getRegulatoryCostsTaxAmountMicros()),
                    'regulatory_costs_total' => Helper::microToBase($invoice->getRegulatoryCostsTotalAmountMicros()),
                    'replaced _invoices' => $invoice->getReplacedInvoices() ? implode( "', '", iterator_to_array($invoice->getReplacedInvoices()->getIterator()) ) : 'none',
                    'invoice_amounts_subtota' => Helper::microToBase($invoice->getSubtotalAmountMicros()),
                    'invoice_amounts_tax' => Helper::microToBase($invoice->getTaxAmountMicros()),
                    'invoice_amounts_total' => Helper::microToBase($invoice->getTotalAmountMicros()),
                    'corrected_invoice' => $invoice->getCorrectedInvoice() ?: 'none',
                    'pdf_url' => $invoice->getPdfUrl()
                ];
                // foreach ($invoice->getAccountBudgetSummaries() as $accountBudgetSummary) {
                //     /** @var AccountBudgetSummary $accountBudgetSummary */
                //     $abs = [
                //         'account_budget_id' => $accountBudgetSummary->getAccountBudget(),
                //         'account_budget_name' => $accountBudgetSummary->getAccountBudgetName() ?: 'none',
                //         'account_name' => $accountBudgetSummary->getCustomer(),
                //         'purchase_order' => $accountBudgetSummary->getCustomerDescriptiveName() ?: 'none',
                //         'inclusive' => $accountBudgetSummary->getPurchaseOrderNumber() ?: 'none',
                //         'billing_activity_from' => $accountBudgetSummary->getBillableActivityDateRange()->getStartDate(),
                //         'biliing_activity_to' => $accountBudgetSummary->getBillableActivityDateRange()->getEndDate(),
                //         'account_budget_subtotal' => Helper::microToBase($accountBudgetSummary->getSubtotalAmountMicros()),
                //         'account_budget_tax' => Helper::microToBase($accountBudgetSummary->getTaxAmountMicros()),
                //         'account_budget_total' => Helper::microToBase($accountBudgetSummary->getTotalAmountMicros())
                //     ];
                // }
               // $row = $invDetail + $abs;

                $fileRequest = Http::withHeaders([
                    'Authorization'=> 'Bearer '.$accessToken,
                    'Content-type'=>'application/pdf'
                ])
                ->get($invDetail['pdf_url']);

                $fileName = $invDetail['invoice_number'];
                $file = $fileRequest->getBody();

                $invBreakdown = $this->extractInvoice($file, $fileName);

                $row = $invDetail; //+ $abs;
                $row['details'] = $invBreakdown;
                array_push($result, $row);
            }
            $period->modify('first day of next month');
        }
      return collect($result);
    }
}
