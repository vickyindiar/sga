<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Google\Ads\GoogleAds\Lib\V6\GoogleAdsClient;
use Google\Ads\GoogleAds\Lib\V6\GoogleAdsClientBuilder;
use Google\Ads\GoogleAds\Lib\V6\GoogleAdsException;
use Google\Ads\GoogleAds\Lib\OAuth2TokenBuilder;
use Google\Ads\GoogleAds\V6\Errors\GoogleAdsError;
use Google\Ads\GoogleAds\Util\V6\ResourceNames;
use Google\ApiCore\ApiException;
use Google\Auth\CredentialsLoader;
use Google\Auth\OAuth2;

class AccountController extends Controller
{

    private const SCOPE = 'https://www.googleapis.com/auth/adwords';
    private const AUTHORIZATION_URI = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const REDIRECT_URI = 'urn:ietf:wg:oauth:2.0:oob';

    public function fetchOAuth2Google(Request $request){
        $oauth2 = new OAuth2(
            [
                'authorizationUri' => self::AUTHORIZATION_URI,
                'redirectUri' => self::REDIRECT_URI,
                'tokenCredentialUri' => CredentialsLoader::TOKEN_CREDENTIAL_URI,
                'clientId' => $request->input('cI'),
                'clientSecret' => $request->input('cS'),
                'scope' => self::SCOPE
            ]
        );
        $result = [
            'uri' => trim(sprintf('%1$s', $oauth2->buildFullAuthorizationUri()))
        ];
        return response()->json($result);
    }

    public function verifyCodeOAuth2Google(Request $request){
        $oauth2 = new OAuth2(
            [
                'authorizationUri' => self::AUTHORIZATION_URI,
                'redirectUri' => self::REDIRECT_URI,
                'tokenCredentialUri' => CredentialsLoader::TOKEN_CREDENTIAL_URI,
                'clientId' => $request->input('cI'),
                'clientSecret' => $request->input('cS'),
                'scope' => self::SCOPE
            ]
        );
        $oauth2->setCode($request->input('verifyCode'));
        $authToken = $oauth2->fetchAuthToken();
        $result = [
            'refreshToken' => $authToken['refresh_token']
        ];
        return response()->json($result);
    }

    public function getCustomerManager($googleAdsClient){

        /**
            *@param GoogleAdsClient $googleAdsClient the Google Ads API client
        */
        $customerServiceClient = $googleAdsClient->getCustomerServiceClient();
        $accessibleCustomers = $customerServiceClient->listAccessibleCustomers();

        $results = [];
        foreach ($accessibleCustomers->getResourceNames() as $resourceName) {
            $id = explode('/', $resourceName);
            try {
                $customer = $customerServiceClient->getCustomer(ResourceNames::forCustomer($id[1]));
                $custDetails = [
                    'id' => $customer->getId(),
                    'name' => $customer->getDescriptiveName(),
                    'isManager' => $customer->getManager()
                ];
                if ($customer->getManager()) { array_push($results, $custDetails); }
            } catch (\Throwable $th) {
                    $custDetails = [];
            }
        }
        return $results;
    }


    public function fetchAllCustomerId(Request $request){
        $results = [];

        $oAuth2Credential =
        (new OAuth2TokenBuilder())->withClientId($request->input('cI'))
        ->withClientSecret($request->input('cS'))
        ->withRefreshToken($request->input('refreshToken'))
        ->build();

        $googleAdsClient = (new GoogleAdsClientBuilder())
        ->withOAuth2Credential($oAuth2Credential)
        ->withDeveloperToken($request->input('devT'))
        ->build();
        $googleAdsServiceClient = $googleAdsClient->getGoogleAdsServiceClient();
        $query = 'SELECT
        customer.id,
        customer.manager,
        customer.resource_name,
        customer_client.id,
        customer_client.descriptive_name,
        customer.descriptive_name
        FROM
            customer_client
        LIMIT
            100';
         /** @var GoogleAdsServerStreamDecorator $stream */
        $managerId = $this->getCustomerManager($googleAdsClient);

        foreach($managerId as $rowManager){
            $stream = $googleAdsServiceClient->searchStream( $rowManager['id'], $query,  ['pageSize' => 1000]); //8517640309
            foreach ($stream->iterateAllElements() as $googleAdsRow) {
                /** @var GoogleAdsRow $googleAdsRow */
                // Converts each result as a Plain Old PHP Object (POPO) using JSON.
                $results[] = json_decode($googleAdsRow->serializeToJsonString(), true);
            }
        }

        $collection = collect($results);
        return response()->json($collection);
    }

    public function fetchCustomerManager(Request $request){

        $oAuth2Credential =
        (new OAuth2TokenBuilder())->withClientId($request->input('cI'))
        ->withClientSecret($request->input('cS'))
        ->withRefreshToken($request->input('refreshToken'))
        ->build();

        $googleAdsClient = (new GoogleAdsClientBuilder())
        ->withOAuth2Credential($oAuth2Credential)
        ->withDeveloperToken($request->input('devT'))
        ->build();
        /**
            *@param GoogleAdsClient $googleAdsClient the Google Ads API client
        */
        $customerServiceClient = $googleAdsClient->getCustomerServiceClient();
        $accessibleCustomers = $customerServiceClient->listAccessibleCustomers();

        $results = [];
        foreach ($accessibleCustomers->getResourceNames() as $resourceName) {
            $id = explode('/', $resourceName);
            try {
                $customer = $customerServiceClient->getCustomer(ResourceNames::forCustomer($id[1]));
                $custDetails = [
                    'id' => $customer->getId(),
                    'name' => $customer->getDescriptiveName(),
                    'isManager' => $customer->getManager()
                ];
                if ($customer->getManager()) { array_push($results, $custDetails); }
            } catch (\Throwable $th) {
                    $custDetails = [];
            }
        }
        return response()->json($results);
    }
}
