<?php

namespace Mayeco\GoogleBundle\Services;

use Google_Client;
use AdWordsUser;
use Lsw\MemcacheBundle\Cache\MemcacheInterface;

class GoogleUtils
{

    protected $adwordsuser;
    protected $adwordsversion;
    protected $apiclient;
    protected $memcache;

    public function __construct(
        AdWordsUser $adwordsuser, 
        Google_Client $apiclient, 
        MemcacheInterface $memcache, 
        $adwordsversion
    ) {

        $this->adwordsuser = $adwordsuser;
        $this->apiclient = $apiclient;
        $this->memcache = $memcache;
        $this->adwordsversion = $adwordsversion;
    }
    
    public function DownloadReportWithAwql($awql, $format="CSV") {
        
        $allowformats = array("CSV", "XML", "TSV", "GZIPPED_CSV", "GZIPPED_XML");
        if (!in_array($format, $allowformats))
            return;
        
        if(!$this->ValidateUser())
            return;
        
        $report = null;
        try {
            
            $report = \ReportUtils::DownloadReportWithAwql($awql, null, $this->adwordsuser, $format);
            
            if("GZIPPED_CSV" == $format || "GZIPPED_XML" == $format)
                $report = gzdecode ($report);

        } catch (\Exception $e) {
            
            return;
        }
        
        return $report;
    }
    
    public function setAdwordsOAuth2Validate($refreshToken, $accessToken) {

        $oauth = $this->adwordsuser->GetOAuth2Info();
        $oauth["refresh_token"] = $refreshToken;
        $oauth["access_token"] = $accessToken;

        $this->adwordsuser->SetOAuth2Info($oauth);

        return $this->ValidateUser();
    }

    public function ValidateUser() {

        try {

            $this->adwordsuser->ValidateUser();

        } catch (\Exception $e) {

            return;
        }

        return true;
    }

    public function GetAdwordsService($service) {

        try {

            $service = $this->adwordsuser->GetService($service, $this->adwordsversion);

        } catch (\Exception $e) {

            return;
        }

        return $service;
    }

    public function GetAdwordsUser() {

        if(!$this->ValidateUser())
            return;

        return $this->adwordsuser;
    }

    public function GetGoogleApi() {

        return $this->apiclient;
    }

    public function createAuthUrl() {
        
        return $this->apiclient->createAuthUrl();
    }

    public function authenticate($code) {

        try {

            $jsontoken = $this->apiclient->authenticate($code);
            $verify_token = $this->apiclient->verifyIdToken();
            $user_id = $verify_token->getUserId();

        } catch (\Exception $e) {

            return;
        }

        $fulltoken = json_decode($jsontoken, true);
        if(!isset($fulltoken["access_token"]) || !isset($fulltoken["refresh_token"]))
            return;

        $this->memcache->set($user_id . '_token', $jsontoken, $fulltoken["expires_in"] - 60);

        return array(
            "refresh_token" => $fulltoken["refresh_token"], 
            "user_id" => $user_id, 
        );
    }

    public function setAdwordsId($adwordsid) {
        
        $this->adwordsuser->SetClientCustomerId($adwordsid);
        
    }

    public function relogin($id, $refreshToken) {

        if( !$jsontoken = $this->memcache->get($id . '_token')  ) {

            try {

                $this->apiclient->refreshToken($refreshToken);
                $verify_token = $this->apiclient->verifyIdToken();
                $user_id = $verify_token->getUserId();

            } catch (\Exception $e) {

                return;
            }
            
            if($user_id != $id)
                return;

            $jsontoken = $this->apiclient->getAccessToken();
            $atributes = $verify_token->getAttributes();
            $payload = $atributes["payload"];

            $this->memcache->set($user_id . '_token', $jsontoken, $payload["exp"] - 60);
        }


        try {

            $this->apiclient->setAccessToken($jsontoken);

        } catch (\Exception $e) {

            return;
        }

        $fulltoken = json_decode($jsontoken, true);
        if(!$this->setAdwordsOAuth2Validate($refreshToken, $fulltoken["access_token"])) {

            return;
        }

        return true;
    }

}
