<?php

namespace App\Http\Controllers;

use DateTime;
use Illuminate\Http\Request;
use Google\Ads\GoogleAds\Lib\V6\GoogleAdsClient;
use Google\Ads\GoogleAds\Lib\V6\GoogleAdsServerStreamDecorator;
use Google\Ads\GoogleAds\Util\FieldMasks;
use Google\Ads\GoogleAds\Util\V6\ResourceNames;
use Google\Ads\GoogleAds\V6\Enums\CampaignStatusEnum\CampaignStatus;
use Google\Ads\GoogleAds\V6\Resources\Campaign;
use Google\Ads\GoogleAds\V6\Services\CampaignOperation;
use Google\Ads\GoogleAds\V6\Services\GoogleAdsRow;
use Google\Ads\GoogleAds\Lib\V6\GoogleAdsClientBuilder;
use Google\Ads\GoogleAds\Lib\OAuth2TokenBuilder;
use Google\Ads\GoogleAds\V6\Enums\CriterionTypeEnum\CriterionType;
use Google\Ads\GoogleAds\V6\Enums\DeviceEnum\Device;
use Google\Ads\GoogleAds\Examples\Utils\Helper;
use App\Http\Controllers\InvoiceController;
use Illuminate\Support\Facades\Storage;

class CampaignController extends Controller
{
    public function fetchAllCampaign(Request $request){

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
        $query = 'SELECT campaign.id, campaign.labels, campaign.name, campaign.status, campaign.start_date, campaign.end_date, campaign.campaign_budget, campaign.advertising_channel_type,
                    segments.device, metrics.clicks, metrics.cost_micros FROM campaign';
         /** @var GoogleAdsServerStreamDecorator $stream */
        $stream = $googleAdsServiceClient->searchStream($request->input('customerId'), $query,  ['pageSize' => 1000]); //8517640309
        // $campaign = json_decode(
        //     $stream->iterateAllElements()->current()->getCampaign()->serializeToJsonString(),
        //     true
        // );
        $results = [];
        foreach ($stream->iterateAllElements() as $googleAdsRow) {
            /** @var GoogleAdsRow $googleAdsRow */
            $results[] = json_decode($googleAdsRow->serializeToJsonString(), true);
        }
        $collection = collect($results);

        return response()->json($collection);
    }

    public function getCampaignInterestFromAdGroup($customerId, $googleAdsClient, $startDate, $endDate){
        $results = [];
        $data = [];
        $googleAdsServiceClient = $googleAdsClient->getGoogleAdsServiceClient();
        $query = 'SELECT user_interest.name, campaign.id, campaign.name, campaign.start_date, campaign.end_date, ad_group.id'
                 .' FROM ad_group_criterion'
                 ." WHERE campaign.start_date >= '" .$startDate."' AND campaign.start_date <= '" .$endDate. "'";
        /** @var GoogleAdsServerStreamDecorator $stream */
        $stream = $googleAdsServiceClient->searchStream($customerId, $query,  ['pageSize' => 1000]);//8517640309
        foreach ($stream->iterateAllElements() as $googleAdsRow) {
             /** @var GoogleAdsRow $googleAdsRow */
             if($googleAdsRow->getUserInterest() != null){
                $items = [
                        'campaignId' => $googleAdsRow->getCampaign()->getId(),
                        'interest' => $googleAdsRow->getUserInterest()->getName()
                ];
                array_push($data, $items);
            }
        }
        $results = collect($data)->groupBy('campaignId');
        return $results;
    }

    public function getCampaingTargetingDetails($googleAdsClient, $customerId, $startDate, $endDate){
        $googleAdsServiceClient = $googleAdsClient->getGoogleAdsServiceClient();
        // Creates a query that retrieves campaign criteria.
        $query = 'SELECT campaign.id, campaign.start_date, campaign.end_date, campaign_criterion.campaign, '
                . 'campaign_criterion.criterion_id, campaign_criterion.type, '
                . 'campaign_criterion.negative, campaign_criterion.keyword.text, '
                . 'campaign_criterion.keyword.match_type, campaign_criterion.device.type, '
                . 'campaign_criterion.gender.type,'
                . 'campaign_criterion.location.geo_target_constant, campaign_criterion.location_group,'
                . 'campaign_criterion.location_group.feed, campaign_criterion.age_range.type FROM campaign_criterion'
                ." WHERE campaign.start_date >= '" .$startDate."' AND campaign.start_date <= '" .$endDate. "'";
                //. ' WHERE campaign.id = '. $campaignId;

           /** @var GoogleAdsServerStreamDecorator $stream */
          $stream = $googleAdsServiceClient->searchStream($customerId, $query,  ['pageSize' => 1000]); //8517640309
          $data = [];
          $interest = $this->getCampaignInterestFromAdGroup($customerId, $googleAdsClient, $startDate, $endDate);
          foreach ($stream->iterateAllElements() as $googleAdsRow) {
                 /** @var GoogleAdsRow $googleAdsRow */
                $campaignId = $googleAdsRow->getCampaign()->getId();
                $geoTargetConstantServiceClient = $googleAdsClient->getGeoTargetConstantServiceClient();
                $campaignCriterion = $googleAdsRow->getCampaignCriterion();

                if (!array_key_exists($campaignId , $data)) {
                    $dataCampaign = [
                        'locations' => [],
                        'devices' => [],
                        'age' => [],
                        'gender' => []
                    ];
                   $data[$campaignId] = $dataCampaign;
               }

                if ($campaignCriterion->getType() === CriterionType::LOCATION) {
                    $response = $geoTargetConstantServiceClient->getGeoTargetConstant($campaignCriterion->getLocation()->getGeoTargetConstant());
                    if (!in_array($response->getName(), $data[$campaignId]['locations'])) {
                         array_push($data[$campaignId]['locations'], $response->getName());
                    }
                }
                if($campaignCriterion->getType() === CriterionType::DEVICE){
                    if (!in_array(Device::name($campaignCriterion->getDevice()->getType()), $data[$campaignId]['devices'])) {
                        array_push($data[$campaignId]['devices'], Device::name($campaignCriterion->getDevice()->getType()));
                    }
                }
                if($campaignCriterion->getType() === CriterionType::AGE_RANGE){
                    if(!in_array( $campaignCriterion->getAgeRange(), $data[$campaignId]['age'])){
                       array_push($data[$campaignId]['age'], $campaignCriterion->getAgeRange());
                    }
                }
                if($campaignCriterion->getType() === CriterionType::GENDER){
                    if(!in_array($campaignCriterion->getGender(), $data[$campaignId]['gender'])){
                       array_push($data[$campaignId]['gender'], $campaignCriterion->getGender());
                    }
                }

                if ($interest->get($campaignId)
                    && array_key_exists($campaignId , $data)
                    && !array_key_exists('interest', $data[$campaignId])
                    ){
                        $data[$campaignId]['interest'] = $interest->get($campaignId)->implode('interest', ', ');
                    }
                    else if(!$interest->get($campaignId)
                    && array_key_exists($campaignId , $data)
                    && !array_key_exists('interest', $data[$campaignId])) {
                        $data[$campaignId]['interest'] = '';
                    }
            }
         $collection = collect($data);
         return $collection;
    }

    public function fetchTrackPerformanceCampaignBudget(Request $request){
        $oAuth2Credential = (new OAuth2TokenBuilder())->withClientId($request->input('cI'))
        ->withClientSecret($request->input('cS'))
        ->withRefreshToken($request->input('refreshToken'))
        ->build();

        $googleAdsClient = (new GoogleAdsClientBuilder())
        ->withLoginCustomerId($request->input('loginCustomerId'))
        ->withDeveloperToken($request->input('devT'))
        ->withOAuth2Credential($oAuth2Credential)
        ->build();


        $customerId = $request->input('customerId');
        $startDate = $request->input('startDate');
        $endDate = $request->input('endDate');

        $googleAdsServiceClient = $googleAdsClient->getGoogleAdsServiceClient();
        $query = 'SELECT metrics.clicks, metrics.interaction_rate,
                     metrics.value_per_conversion, metrics.impressions, metrics.cost_micros, metrics.average_cost, metrics.average_cpc, metrics.interactions,
                    campaign_budget.amount_micros, campaign_budget.id, campaign_budget.name, campaign_budget.period, campaign_budget.type,
                    campaign_budget.total_amount_micros, campaign_budget.status, campaign.id, campaign.labels, campaign.name, campaign.bidding_strategy_type,
                    campaign.advertising_channel_type, campaign.ad_serving_optimization_status, campaign.targeting_setting.target_restrictions,
                    campaign.serving_status, campaign.optimization_goal_setting.optimization_goal_types, campaign.status,
                    campaign.start_date, campaign.end_date
                    FROM campaign_budget'
                   //." WHERE campaign.start_date BETWEEN '".$startDate."' AND '" .$endDate."'";
                   ." WHERE campaign.start_date >= '" .$startDate."' AND campaign.start_date <= '" .$endDate. "'";
                   //." ORDER BY campaign.id";
         /** @var GoogleAdsServerStreamDecorator $stream */
        $stream = $googleAdsServiceClient->searchStream($customerId, $query,   ['pageSize' => 1000]); //8517640309

        $results = [];
        $details = $this->getCampaingTargetingDetails( $googleAdsClient, $customerId, $startDate, $endDate);
        /** @var \Illuminate\Support\Collection $invoice */
        $invoice = (new InvoiceController)->getCollectionInvoice($googleAdsClient, $customerId, $startDate, $endDate);

        foreach ($stream->iterateAllElements() as $googleAdsRow) {
            /** @var GoogleAdsRow $googleAdsRow */
            $campaignId = $googleAdsRow->getCampaign()->getId();
            $campaignName = $googleAdsRow->getCampaign()->getName();
            $results[] = json_decode($googleAdsRow->serializeToJsonString(), true);

            //append targeting informations
            $results[count($results) - 1]['campaign']['location'] = implode(' ', $details->get($campaignId)['locations']);
            $results[count($results) - 1]['campaign']['device'] = implode(' ',  $details->get($campaignId)['devices']);
            //info by google : By default a new campaign will contain no age range criteria, which means that all ages are included.
            $results[count($results) - 1]['campaign']['age'] = implode(' ',  $details->get($campaignId)['age']) == '' ? '18-65+' : implode(' ',  $details->get($campaignId)['age']);
            $results[count($results) - 1]['campaign']['gender'] = implode(' ',  $details->get($campaignId)['gender']) == '' ? 'Male - Female' : implode(' ',  $details->get($campaignId)['gender']);
            $results[count($results) - 1]['campaign']['interest'] = $details->get($campaignId)['interest'];

            //modify matrics from micro to normal/base decimal
            if (array_key_exists('averageCost' , $results[count($results) - 1]['metrics']) && $results[count($results) - 1]['metrics']['averageCost'] != 0) {
                $results[count($results) - 1]['metrics']['averageCost'] = round(Helper::microToBase($results[count($results) - 1]['metrics']['averageCost']), 2);
            }

            if (array_key_exists('averageCpc' , $results[count($results) - 1]['metrics']) && $results[count($results) - 1]['metrics']['averageCpc'] != 0) {
                $results[count($results) - 1]['metrics']['averageCpc'] = round(Helper::microToBase($results[count($results) - 1]['metrics']['averageCpc']), 2);
            }

            if (array_key_exists('costMicros' , $results[count($results) - 1]['metrics']) && $results[count($results) - 1]['metrics']['costMicros'] != '0') {
                $results[count($results) - 1]['metrics']['costMicros'] = round(Helper::microToBase(floatval($results[count($results) - 1]['metrics']['costMicros'])), 2);
            }

            //append invoice informations
            if(count($invoice->all()) > 0){
                $invList = [];
                foreach ($invoice->all() as $key => $value) {
                    $detailbyCampaign = collect($value['details']);
                    $detailbyCampaign = $detailbyCampaign->filter(function($v) use($campaignName) {
                        return $v['Campaign'] == $campaignName;
                    });
                    if(count($detailbyCampaign) > 0){
                        $inv =  [
                            'invoiceId' => $value['invoice_number'],
                            'invoiceDate' => $value['invoice_date'],
                            'invoiceType' => $value['type'],
                            'invoiceDue' => $value['due_date'],
                            'invoiceCurrency'=> $value['currency_code'],
                            'invoiceFrom'=> $value['from'],
                            'invoiceTo'=> $value['to'],
                            'invoiceSubTotal'=> $value['invoice_amounts_subtota'],
                            'invoiceTax'=> $value['invoice_amounts_tax'],
                            'invoiceTotal'=> $value['invoice_amounts_total'],
                            'invoiceCampaignClicks' => $detailbyCampaign[0]['Quantity'],
                            'invoiceCampaignUnits' => $detailbyCampaign[0]['Units'],
                            'invoiceCampaignCost'  => $detailbyCampaign[0]['Amount']
                        ];
                        array_push($invList,$inv);
                    }
                }
                $results[count($results) - 1]['invoice'] = $invList;
            }
            else{
                $results[count($results) - 1]['invoice'] = [];

                //::TEST ONLY REMOVE LATER
                $invList = [];
               // if(file_exists(Storage::path('google-sample-invoice.json'))){
               //     $jsonString = file_get_contents(Storage::path('google-sample-invoice.json'));
                if ($request->exists('invData')) {
                    $jsonString = $request->input('invData');
                    $sampleData = json_decode($jsonString, true);
                    foreach ($sampleData as $key => $value) {
                        if($value['campaignId'] ==  $campaignId){
                            $inv =  [
                                'invoiceId' => $value['invoiceId'],
                                'invoiceDate' => $value['invoiceDate'],
                                'invoiceType' => $value['invoiceType'],
                                'invoiceDue' => $value['invoiceDue'],
                                'invoiceCurrency'=> $value['invoiceCurrency'],
                                'invoiceFrom'=> $value['invoiceFrom'],
                                'invoiceTo'=> $value['invoiceTo'],
                                'invoiceSubTotal '=> $value['invoiceSubTotal'],
                                'invoiceTax' => $value['invoiceTax'],
                                'invoiceTotal'=> $value['invoiceTotal'],
                                'invoiceCampaignClicks' => $value['invoiceCampaignClicks'],
                                'invoiceCampaignUnits' => $value['invoiceCampaignUnits'],
                                'invoiceCampaignCost'  => $value['invoiceCampaignCost']
                            ];
                            array_push($invList,$inv);
                        }
                    }
                    $results[count($results) - 1]['invoice'] = $invList;
                }
            }
        }
        $collection = collect($results);
        return response()->json($collection);
    }

    public function fecthTargetingCriteria(Request $request){
       $results = [];
       $oAuth2Credential = (new OAuth2TokenBuilder())->withClientId($request->input('cI'))
       ->withClientSecret($request->input('cS'))
       ->withRefreshToken($request->input('refreshToken'))
       ->build();

       $googleAdsClient = (new GoogleAdsClientBuilder())
       ->withLoginCustomerId($request->input('loginCustomerId'))
       ->withDeveloperToken($request->input('devT'))
       ->withOAuth2Credential($oAuth2Credential)
       ->build();

       $googleAdsServiceClient = $googleAdsClient->getGoogleAdsServiceClient();
       // Creates a query that retrieves campaign criteria.
       $query = 'SELECT campaign.id,  campaign.start_date, campaign.end_date, campaign_criterion.campaign, '
               . 'campaign_criterion.criterion_id, campaign_criterion.type, '
               . 'campaign_criterion.negative, campaign_criterion.keyword.text, '
               . 'campaign_criterion.keyword.match_type, campaign_criterion.device.type, '
               . 'campaign_criterion.gender.type,'
               . 'campaign_criterion.location.geo_target_constant, campaign_criterion.location_group,'
               . 'campaign_criterion.user_interest.user_interest_category, user_interest.name, user_interest.resource_name, campaign_criterion.user_list.user_list,'
               . 'campaign_criterion.location_group.feed, campaign_criterion.age_range.type FROM campaign_criterion ';

        /** @var GoogleAdsServerStreamDecorator $stream */
         $stream = $googleAdsServiceClient->searchStream($request->input('customerId'), $query,  ['pageSize' => 1000]); //8517640309
        // $geoTargetConstantServiceClient = $googleAdsClient->getGeoTargetConstantServiceClient();
        // $campaignService = $googleAdsClient->getCampaignServiceClient();
         foreach ($stream->iterateAllElements() as $googleAdsRow) {
            /** @var GoogleAdsRow $googleAdsRow */
            // Converts each result as a Plain Old PHP Object (POPO) using JSON.
          //  $campaignCriterion = $googleAdsRow->getCampaignCriterion();

            $results[] = $googleAdsRow->getCampaign()->getId();
           //$results[] = json_decode($campaignCriterion->serializeToJsonString(), true);
        }
        return response()->json($results);

    }

    public function fetchAdGroupInterest(Request $request){
        $results = [];

        $oAuth2Credential = (new OAuth2TokenBuilder())->withClientId($request->input('cI'))
        ->withClientSecret($request->input('cS'))
        ->withRefreshToken($request->input('refreshToken'))
        ->build();

        $googleAdsClient = (new GoogleAdsClientBuilder())
        ->withLoginCustomerId($request->input('loginCustomerId'))
        ->withDeveloperToken($request->input('devT'))
        ->withOAuth2Credential($oAuth2Credential)
        ->build();

        $googleAdsServiceClient = $googleAdsClient->getGoogleAdsServiceClient();
        $query = 'SELECT user_interest.name, campaign.id, ad_group.id, ad_group_criterion.age_range.type, campaign.name  FROM ad_group_criterion';// WHERE campaign.id = ' .$campaignId;
        /** @var GoogleAdsServerStreamDecorator $stream */
        $stream = $googleAdsServiceClient->searchStream($request->input('customerId'), $query,  ['pageSize' => 1000]); //8517640309
      //  $userInterestServiceClient = $googleAdsClient->getUserInterestServiceClient();
        foreach ($stream->iterateAllElements() as $googleAdsRow) {
             /** @var GoogleAdsRow $googleAdsRow */
            // if($googleAdsRow->getUserInterest() != null){
            //     array_push($results, $googleAdsRow->getUserInterest()->getName());
            // }
           $results[] = json_decode($googleAdsRow->serializeToJsonString(), true);
        }
        return response()->json($results);
    }
}

