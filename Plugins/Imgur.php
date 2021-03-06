<?php
/**
 * You may upload to your account or without account.
 *
 * Update Oct 06, 2014: Use API ver 3
 */

class ChipVN_ImageUploader_Plugins_Imgur extends ChipVN_ImageUploader_Plugins_Abstract
{
    const AUTH_ENDPOINT  = 'https://api.imgur.com/oauth2/authorize';
    const TOKEN_ENDPOINT = 'https://api.imgur.com/oauth2/token';
    const UPLOAD_ENPOINT = 'https://api.imgur.com/3/image';

    const ACCESS_TOKEN   = 'access_token';
    const REFRESH_TOKEN  = 'refresh_token';

    /**
     * Client secret.
     *
     * @var string
     */
    protected $secret;

    /**
     * Set client_secret
     *
     * @param string $secret
     */
    public function setSecret($secret)
    {
        $this->secret = $secret;
    }

    /**
     * {@inheritdoc}
     */
    protected function doLogin()
    {
        if (!$this->getCache()->get(self::ACCESS_TOKEN)) {
            if ($this->refreshToken()) return true;

            // get auth page
            $this->resetHttpClient();
            $this->client->setParameters(array(
                'client_id'     => $this->apiKey,
                'response_type' => 'token',
                'state'         => 'STATE'
            ));
            $this->client->execute(self::AUTH_ENDPOINT);
            $this->checkHttpClientErrors(__METHOD__);

            // get allow value
            $allowValue = $this->getMatch('#id=[\'"]allow[\'"].*?value="([^"]+)"#', $this->client);
            $target     = $this->client->getTarget();
            $cookies    = $this->client->getResponseCookies();

            // submit for get access token
            $this->resetHttpClient();
            $this->client->setParameters(array(
                'allow'    => $allowValue,
                'username' => $this->username,
                'password' => $this->password,
            ));
            $this->client->setCookies($cookies);
            $this->client->setReferer($target);
            $this->client->execute($target, 'POST');
            $this->checkHttpClientErrors(__METHOD__);

            $result = json_decode($this->client, true);
            if ($error = $this->getElement($result, 'data.error')) {
                $this->throwException('%s: %s.', __METHOD__, $error);
            }

            $location = $this->client->getResponseHeaders('location');

            $keys = array('access_token', 'expires_in', 'token_type', 'refresh_token');
            $tokens = array();
            foreach ($keys as $key) {
                $$key = $this->getMatch('#'.$key.'=([^&$]+)#', $location);
                if (empty($$key)) {
                    $this->throwException('%s: Missing "%s".', __METHOD__, $key);
                }
                $tokens[$key] = $$key;
            }
            $this->getCache()->set(self::ACCESS_TOKEN, $access_token, 900);
            $this->getCache()->set(self::REFRESH_TOKEN, $refresh_token, 86400);
        }

        return true;
    }

    protected function refreshToken()
    {
        if ($refreshToken = $this->getCache()->get(self::REFRESH_TOKEN)) {
            $client = $this->createHttpClient();
            $client->setParameters(array(
                'refresh_token' => $refreshToken,
                'client_id'     => $this->apiKey,
                'client_secret' => $this->secret,
                'grant_type'    => 'refresh_token',
            ));
            $client->execute(self::TOKEN_ENDPOINT, 'POST');

            $result = json_decode($client, true);
            if (
                ($accessToken = $this->getElement($result, 'access_token'))
                && ($refreshToken = $this->getElement($result, 'refresh_token'))
            ) {
                $this->getCache()->set(self::ACCESS_TOKEN, $accessToken, 900);
                $this->getCache()->set(self::REFRESH_TOKEN, $refreshToken, 86400);

                return true;
            }
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    protected function doUpload()
    {
        if (!$this->useAccount) {
            return $this->doFreeUpload();
        }

        return $this->callUploadApi(array('image' => '@' . $this->file), __METHOD__, 'Upload failed.');
    }

    /**
     * {@inheritdoc}
     */
    protected function doTransload()
    {
        if (!$this->useAccount) {
            return $this->doFreeTransload();
        }

        return $this->callUploadApi(array('image' => $this->url), __METHOD__, 'Transload failed.');
    }

    private function callUploadApi($params, $method, $errorMessage)
    {
        $this->resetHttpClient();
        $this->client->setSubmitMultipart();
        $this->client->setHeaders(array(
            'Authorization' => sprintf('Bearer %s', $this->getCache()->get(self::ACCESS_TOKEN))
        ));
        $this->client->execute(self::UPLOAD_ENPOINT, 'POST', $params);
        $this->checkHttpClientErrors($method);

        $result = json_decode($this->client, true);

        if ($link = $this->getElement($result, 'data.link')) {
            return $link;
        } elseif ($error = $this->getElement($result, 'data.error')) {
            $this->throwException('%s: %s', $method, $error);
        } else {
            $this->throwException('%s: %s', $method, $errorMessage);
        }
    }

    /**
     * Free upload also the image may remove after a period of time
     *
     * @return string    Image URL after upload
     * @throws Exception if upload failed
     */
    private function doFreeUpload()
    {
        list($sid, $session) = $this->getFreeSession();

        $this->resetHttpClient();
        $this->client->setSubmitMultipart();
        $this->client->setHeaders(array(
            'X-Requested-With' => 'XMLHttpRequest',
            'Referer'          => 'http://imgur.com/',
        ));
        $this->client->setCookies($session);
        $this->client->execute('http://imgur.com/upload', 'POST', array(
            'current_upload' => 1,
            'total_uploads'  => 1,
            'terms'          => 0,
            'album_title'    => self::POWERED_BY,
            'gallery_title'  => self::POWERED_BY,
            'sid'            => $sid,
            'Filedata'       => '@' . $this->file,
        ));
        $this->checkHttpClientErrors(__METHOD__);

        $result = json_decode($this->client, true);

        return $this->handleJsonData($this->url, __METHOD__, $result, 'Free upload failed.');
    }

    /**
     * Free transload also the image may remove after a period of time
     *
     * @return string    Image URL after transload
     * @throws Exception if upload failed
     */
    private function doFreeTransload()
    {
        list($sid, $session) = $this->getFreeSession();

        $this->resetHttpClient();
        $this->client->setHeaders(array(
            'X-Requested-With' => 'XMLHttpRequest',
            'Referer'          => 'http://imgur.com/',
        ));
        $this->client->setCookies($session);
        $this->client->execute('http://imgur.com/upload', 'POST', array(
            'current_upload' => 1,
            'total_uploads'  => 1,
            'terms'          => 0,
            'album_title'    => self::POWERED_BY,
            'gallery_title'  => self::POWERED_BY,
            'sid'            => $sid,
            'url'            => $this->url,
        ));
        $this->checkHttpClientErrors(__METHOD__);

        $result = json_decode($this->client, true);

        return $this->handleJsonData($this->url, __METHOD__, $result, 'Free transload failed.');
    }

    /**
     * Handle generic json data.
     *
     * @param  string      $file         Image file or image url
     * @param  string      $method       Method called
     * @param  array       $result
     * @param  string|null $errorMessage
     * @return string      Image url.
     * @throws Exception   if catch any error.
     */
    private function handleJsonData($file, $method, $result, $errorMessage = null)
    {
        if ($hash = $this->getElement($result, 'data.hash')) {
            return 'http://i.imgur.com/' . $hash . $this->getExtensionFormImage($file);
        } elseif ($error = $this->getElement($result, 'data.error')) {
             $this->throwException('%s: %s (%d).', $method, $error['message'], $error['code']);
        } else {
            $this->throwException('%s: ' . ($errorMessage ? $errorMessage : 'Upload failed.'), $method);
        }
    }

    /**
     * Gets free session id
     *
     * @return array     [sessionId, session]
     * @throws Exception if catch any error
     */
    private function getFreeSession()
    {
        if (!$this->getCache()->get('free_sid')) {
            $this->resetHttpClient();
            $this->client->execute('http://imgur.com/upload/start_session');
            $this->checkHttpClientErrors(__METHOD__);

            $result = json_decode($this->client, true);

            if (isset($result['sid'])) {
                $this->getCache()->set('free_sid', $result['sid']);
                $this->getCache()->set('free_session', $this->client->getResponseCookies());
            } else {
                $this->throwException('%s: Cannot get free IMGURSESSION.', __METHOD__);
            }
        }

        return array($this->getCache()->get('free_sid'), $this->getCache()->get('free_session'));
    }

    /**
     * Get extension for image url (free upload or transload)
     * This method help to don't need to read the page after upload completed to get extension for the image
     *
     * @param  string $fileName
     * @return string
     */
    private function getExtensionFormImage($fileName)
    {
        // .bmp -> .jpg
        return $this->getMatch('#\.(gif|jpg|jpeg|png)$#i', $fileName, 0, '.jpg');
    }
}
