<?php

namespace Mayeco\GoogleBundle\Services;

use Google_Client;
use AdWordsUser;
use Lsw\MemcacheBundle\Cache\AntiDogPileMemcache;

class GoogleUtils
{

    protected $adwordsuser;
    protected $adwordsversion;
    protected $apiclient;
    protected $memcache;

    public function __construct(
        AdWordsUser $adwordsuser, 
        Google_Client $apiclient, 
        AntiDogPileMemcache $memcache, 
        $adwordsversion
    ) {

        $this->adwordsuser = $adwordsuser;
        $this->apiclient = $apiclient;
        $this->memcache = $memcache;
        $this->adwordsversion = $adwordsversion;
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

        } catch (\Exception $e) {

            return;
        }

        $fulltoken = json_decode($jsontoken, true);
        if(!isset($fulltoken["access_token"]))
            return;

        $q = "https://www.googleapis.com/oauth2/v1/userinfo?alt=json&access_token=" . $fulltoken["access_token"];
        $json = file_get_contents($q);
        if(false === $json)
            return;

        $userinfo = json_decode($json, true);
        if(isset($userinfo['error']))
            return;

        $this->memcache->set($userinfo['id'] . '_token', $jsontoken, $fulltoken["expires_in"] - 30);

        return array("jsontoken" => $jsontoken, "fulltoken" => $fulltoken, "userinfo" => $userinfo);
    }

    public function Relogin($googleid, $refreshToken) {

        if( !$jsontoken = $this->memcache->get($googleid . '_token')  ) {

            try {

                $this->apiclient->refreshToken($refreshToken);

            } catch (\Exception $e) {

                return;
            }

            $jsontoken = $this->apiclient->getAccessToken();
            $fulltoken = json_decode($jsontoken, true);

            $this->memcache->set($googleid . '_token', $jsontoken, $fulltoken["expires_in"] - 30);
        }


        try {

            $this->apiclient->setAccessToken($jsontoken);

        } catch (\Exception $e) {

            return;
        }


        if($this->apiclient->isAccessTokenExpired()) {

            return;
        }

        $fulltoken = json_decode($jsontoken, true);
        if(!$this->setAdwordsOAuth2Validate($refreshToken, $fulltoken["access_token"])) {

            return;
        }

        return $fulltoken;

    }

}
