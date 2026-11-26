<?php
class MsmartSearchLiveConfig {
	public static function get($configService) {
		$config = (array) $configService->get('msmart_search_lf');

		$defaults = array(
			'mode' => 'standard',
			'input_selector' => '.header-wrapper .search-field input[name=search]',
			'min_length' => 1, 'max_height' => 340, 'max_height_tabs' => 430, 'product_columns' => 3,
			'show_button_view_all' => '1', 'show_header' => '1', 'show_header_categories' => '1',
			'limit' => 10,
			'enabled' => $configService->get('msmart_search_enabled') ? '1' : '0',
			'enabled_categories' => '1',
			'limit_similar_phrases' => 10,
			'img_width' => 56,
			'img_height' => 56,
			'img_width_tabs' => 122,
			'img_height_tabs' => 122,
			'img_width_categories' => 40,
			'img_height_categories' => 40,
			'show_image' => '1',
			'show_price' => '1',
			'show_manufacturer' => '1',
			'show_model' => '0',
			'show_image_categories' => '0',
			'show_description_categories' => '0',
			'description_max_length_categories' => 100,
		);

		foreach( $defaults as $key => $value ) {
			if( ! isset( $config[$key] ) || $config[$key] === '' ) {
				$config[$key] = $value;
			}
		}

		$settings = (array)$configService->get('msmart_search_s');
		if (!empty($settings['history_enabled'])) { $config['history_enabled'] = '1'; }
		unset($config['custom_css']);
		return $config;
	}
}
