<?php

namespace IdnoPlugins\Bitly {

    class Main extends \Idno\Common\Plugin {

	public static $AUTHORIZATION_ENDPOINT = 'https://bitly.com/oauth/authorize';
	public static $TOKEN_ENDPOINT = 'https://api-ssl.bitly.com/oauth/access_token';
	
	public static function getRedirectUrl() {
	    return \Idno\Core\site()->config()->url . 'bitly/callback';
	}

	public static function getState() {
	    return md5(\Idno\Core\site()->config()->site_secret . \Idno\Core\site()->config()->url . dirname(__FILE__));
	}

	/**
	 * Connect to Bitly
	 * @return bool|\IdnoPlugins\Bitly\Client
	 */
	function connect() {
	    if (!empty(\Idno\Core\site()->config()->bitly)) {
		$api = new Client(
			\Idno\Core\site()->config()->bitly['client_id'], \Idno\Core\site()->config()->bitly['client_secret']
		);
		return $api;
	    }
	    return false;
	}

	/**
	 * Can the current user use Bitly?
	 * @return bool
	 */
	function hasBitly($user = null) {
	    if (!$user) $user=\Idno\Core\site()->session()->currentUser();
	    if (\Idno\Core\site()->session()->currentUser()->bitly) {
		return true;
	    }
	    return false;
	}
	
	function hasGAT() {
	    return isset(\Idno\Core\site()->config->config['bitly']['generic_access_token']);
	}

	function registerPages() {

	    // Register admin settings
	    \Idno\Core\site()->addPageHandler('admin/bitly', '\IdnoPlugins\Bitly\Pages\Admin');
	    \Idno\Core\site()->template()->extendTemplate('admin/menu/items', 'admin/bitly/menu');

	    \Idno\Core\site()->addPageHandler('account/bitly', '\IdnoPlugins\Bitly\Pages\Account');
	    \Idno\Core\site()->template()->extendTemplate('account/menu/items', 'account/bitly/menu');

	    // Register the callback URL
	    \Idno\Core\site()->addPageHandler('bitly/callback', '\IdnoPlugins\Bitly\Pages\Callback');

	    // Listen to link text expand
	    \Idno\Core\site()->addEventHook('url/expandintext', function(\Idno\Core\Event $event) {
		
		if ($object = $event->data()['object']) {
		
		    if ($owner = $object->getOwner()) {
		
			// See if the owner has bitly, or if we've got a global shortner, if not, don't bother
			if ($this->hasBitly($owner) || $this->hasGAT()) {
		
			    // Get body
			    $body = $event->response();
			    
			    // Load/create lookup table
			    $urls = $object->url_expansion_lookup;
			    if (!$urls) $urls = [];
			    
			    // Find urls
			    if (preg_match_all('#\bhttps?://[^\s()<>]+(?:\([\w\d]+\)|([^[:punct:]\s]|/))#', $body, $matches))
			    {
				foreach($matches[0] as $match) {
				    
				    $longurl = false;
				    if (isset($urls[$match])) {
					$longurl = $urls[$match];
				    } else {
					
					// We haven't encountered this url, so try and expand it
					
					// Try user's bitly
					if ($this->hasBitly($owner) ) { 
					    $result = \Idno\Core\Webservice::get('https://api-ssl.bitly.com/v3/expand', [
							'access_token' => $owner->bitly['access_token'],
							'shortUrl' => $match
					    ]);

					    $result = json_decode($result['content']);
					    if (isset($result->data->expand[0]->long_url))
						$longurl = $result->data->expand[0]->long_url;
					}
					
					// Now try GAT
					if (!$longurl && $this->hasGAT()) {
					    $result = \Idno\Core\Webservice::get('https://api-ssl.bitly.com/v3/expand', [
							'access_token' => \Idno\Core\site()->config->config['bitly']['generic_access_token'],
							'shortUrl' => $match
					    ]);

					    $result = json_decode($result['content']);
					    if (isset($result->data->expand[0]->long_url))
						$longurl = $result->data->expand[0]->long_url;
					}
				    }
				    
				    // Save expanded form, or that we failed.
				    $urls[$match] = $longurl;
				    
				    // Now do a replace
				    if ($longurl!==false) {
					$body = str_replace($match, $longurl, $body);
				    }
				}
			    }
			    
			    // Save updated urls list
			    if (!empty($urls))
			    {
				$object->url_expansion_lookup = $urls;
				$object->save();
			    }
			    
			    // Update body
			    $event->setResponse($body);
			}
		    
		    }
		}
	    });
	    
	    // Handle shorten event
	    \Idno\Core\site()->addEventHook('url/shorten', function(\Idno\Core\Event $event) {

		// Try user binding first
		if ($this->hasBitly()) {
		    $access_token = \Idno\Core\site()->session()->currentUser()->bitly['access_token'];
		    // Generic access token shorten
		} else if (isset(\Idno\Core\site()->config->config['bitly'])) {
		    // Shorten with GAT
		    $access_token = \Idno\Core\site()->config->config['bitly']['generic_access_token'];
		}

		if ($access_token) {

		    $url = $event->response();

		    try {
			$result = \Idno\Core\Webservice::get('https://api-ssl.bitly.com/v3/shorten', [
				    'access_token' => $access_token,
				    'longUrl' => $url
			]);

			$result = json_decode($result['content']);

			if ($result && $result->status_txt == 'OK') {
			    $event->setResponse($result->data->url);
			} else
			    throw new \Exception("There was a problem shortening that link...");
		    } catch (\Exception $e) {
			\Idno\Core\site()->session()->addMessage($e->getMessage(), 'alert-warn');
		    }
		}
	    });
	}

    }

}
