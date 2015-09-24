<?php

class Shopware_Plugins_Frontend_Boxalino_Event
{
    const TRACKER_URL = 'https://cdn.bx-cloud.com/frontend/analytics/en/track';

    protected $params;
    protected $referer;
    public function __construct($event, $params) {
        if (empty($event)) {
            Shopware()->PluginLogger()->debug("event must be set, received: '$event', event could not be tracked");
            return;
        }
        if(!array_key_exists('_a', $params)) {
            $params['_a'] = Shopware_Plugins_Frontend_Boxalino_P13NHelper::getAccount();
        }
        if(!array_key_exists('_ev', $params)) {
            $params['_ev'] = $event;
        }
        if(!array_key_exists('_t', $params)) {
            $params['_t'] = round(microtime(true) * 1000);
        }
        $cems = Shopware()->Front()->Request()->getCookie('cems');
        $cemv = Shopware()->Front()->Request()->getCookie('cemv');
        if(!array_key_exists('_bxs', $params)) {
            $params['_bxs'] = empty($cems) ? self::getSessionId() : $cems;
        }
        if(!array_key_exists('_bxv', $params)) {
            $params['_bxv'] = empty($cemv) ? self::getSessionId() : $cemv;
        }
        if(array_key_exists('referer', $params)) {
            $this->referer = $params['referer'];
            unset($params['referer']);
        } else {
            $this->referer = array_key_exists('HTTP_REFERER', $_SERVER) ? $_SERVER['HTTP_REFERER'] : '';
        }
        $this->params = $params;
    }

    public function track() {
        $finalUrl = self::TRACKER_URL;
        $encodedParams = array();
        if (is_array($this->params)) {
            foreach ($this->params as $key => $value) {
                $encodedParams[] = rawurlencode($key) . '=' . rawurlencode($value);
            }
        }
        if (count($encodedParams)) {
            $finalUrl .= '?' . implode('&', $encodedParams);
        }
        $s = curl_init();
        curl_setopt_array(
            $s,
            array(
                CURLOPT_HTTPHEADER => array(
                    'User-Agent: ' . self::getUserAgent(),
                    'X-Forwarded-For: ' . self::getRemoteIp(),
                    'Referer: ' . $this->referer,
                ),
                CURLOPT_URL => $finalUrl,
                CURLOPT_RETURNTRANSFER => FALSE,
                CURLOPT_CONNECTTIMEOUT => 1, // connection timeout
                CURLOPT_TIMEOUT => 1, // request timeout
                CURLOPT_CUSTOMREQUEST => 'GET',
                CURLOPT_ENCODING => 'identity',
                CURLOPT_HEADER => FALSE, // don't return the headers in the output
            )
        );
        $result = curl_exec($s);
        curl_close($s);
        return $result;
    }

    public static function getSessionId() {
        $r = array(
            bin2hex(crc32(self::getUserAgent())),
            bin2hex(rand(1,10)),
            bin2hex(mt_rand(1,1000)),
            bin2hex(microtime(true)),
        );
        return implode('.', $r);
    }

    public static function getUserAgent() {
        return array_key_exists('HTTP_USER_AGENT', $_SERVER) ? $_SERVER['HTTP_USER_AGENT'] : '';
    }

    public static function getRemoteIp() {
        if (array_key_exists('HTTP_CLIENT_IP', $_SERVER) && !empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (array_key_exists('HTTP_X_FORWARDED_FOR', $_SERVER) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        return $ip;
    }
}