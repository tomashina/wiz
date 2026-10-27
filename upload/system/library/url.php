<?php
/**
 * @package		OpenCart
 * @author		Daniel Kerr
 * @copyright	Copyright (c) 2005 - 2017, OpenCart, Ltd. (https://www.opencart.com/)
 * @license		https://opensource.org/licenses/GPL-3.0
 * @link		https://www.opencart.com
*/

/**
* URL class
*/
class Url {
	private $url;
	private $ssl;
	private $rewrite = array();
	
	/**
	 * Constructor
	 *
	 * @param	string	$url
	 * @param	string	$ssl
	 *
 	*/
	public function __construct($url, $ssl = '') {
		$this->url = $url;
		$this->ssl = $ssl;
	}

	/**
	 *
	 *
	 * @param	object	$rewrite
 	*/	
	public function addRewrite($rewrite) {
		$this->rewrite[] = $rewrite;
	}

	/**
	 * 
	 *
	 * @param	string		$route
	 * @param	mixed		$args
	 * @param	bool		$secure
	 *
	 * @return	string
 	*/
	public function link($route, $args = '', $secure = false) {
		// Product URLs must be identical in listings, canonical tags and sitemaps.
		if ($route === 'product/product') {
			if (!is_array($args)) {
				parse_str(html_entity_decode((string)$args, ENT_QUOTES, 'UTF-8'), $args);
			}

			foreach (array('path', 'manufacturer_id', 'search', 'tag', 'description', 'category_id', 'sub_category', 'sort', 'order', 'page', 'limit', 'filter', 'filter_name', 'filter_sub_category', 'filter_description') as $key) {
				unset($args[$key]);
			}

			$secure = true;
		}

		if ($this->ssl && $secure) {
			$url = $this->ssl . 'index.php?route=' . $route;
		} else {
			$url = $this->url . 'index.php?route=' . $route;
		}
		
		if ($args) {
			if (is_array($args)) {
				$url .= '&amp;' . http_build_query($args, '', '&amp;');
			} else {
				$url .= str_replace('&', '&amp;', '&' . ltrim($args, '&'));
			}
		}
		
		foreach ($this->rewrite as $rewrite) {
			$url = $rewrite->rewrite($url);
		}
		
		return $url; 
	}
}
