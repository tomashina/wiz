jQuery(function(){
	if( typeof msmartSearchParams == 'undefined' ) return;
	
	var config = msmartSearchParams['lf'],
		lang = {},
		$btnMore = $('<a class="mss-button-more" href="#">'),
		$datasetProducts = null,
		$datasetCategories = null,
		tabs = {},
		keyword,
		mode = config.mode||'standard';
		
	if( typeof MSS_MODE != 'undefined' ) {
		mode = MSS_MODE;
	}
	
	if( config.enabled != '1' && ( mode == 'tabs' || config.enabled_categories != '1' ) ) return;

	function addphrase(){
		if( config.history_enabled != '1' ) return;
		
		$.get($('base').attr('href') + 'index.php?route=extension/module/msmart_search/savephrase&phrase=' + encodeURIComponent( keyword ));
	}
	
	function url_search( v ) {
		var url = $('base').attr('href') + 'index.php?route=product/search';
		v = v || '';
		
		if( v !== '' ) {
			url += '&search=' + encodeURIComponent( v );
		}
		
		if( config.search_in_product_descriptions == '1' ) {
			url += '&description=1';
		}
		
		return url;
	}
	
	function base64_decode(data) {
		var b64 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=';
		var o1, o2, o3, h1, h2, h3, h4, bits, i = 0,
		  ac = 0,
		  dec = '',
		  tmp_arr = [];

		if (!data) {
		  return data;
		}

		data += '';

		do {
		  h1 = b64.indexOf(data.charAt(i++));
		  h2 = b64.indexOf(data.charAt(i++));
		  h3 = b64.indexOf(data.charAt(i++));
		  h4 = b64.indexOf(data.charAt(i++));

		  bits = h1 << 18 | h2 << 12 | h3 << 6 | h4;

		  o1 = bits >> 16 & 0xff;
		  o2 = bits >> 8 & 0xff;
		  o3 = bits & 0xff;

		  if (h3 == 64) {
			tmp_arr[ac++] = String.fromCharCode(o1);
		  } else if (h4 == 64) {
			tmp_arr[ac++] = String.fromCharCode(o1, o2);
		  } else {
			tmp_arr[ac++] = String.fromCharCode(o1, o2, o3);
		  }
		} while (i < data.length);

		dec = tmp_arr.join('');

		return dec.replace(/\0+$/, '');
	};
	
	var $input = $(config.input_selector).typeahead({
		hint: true,
		highlight: true,
		minLength: typeof config.min_length == 'undefined' ? 1 : parseInt( config.min_length ),
		classNames: {
			wrapper: 'msmart-search-live-filter mslf-mode-' + mode + ( typeof MSS_LANG_DIRECTION != 'undefined' ? ' msmart-search-language-direction-' + MSS_LANG_DIRECTION : '' )
		}
	}, {
		name: 'name',
		displayKey: 'name',
		valueKey: 'id',
		source: new Bloodhound({
			datumTokenizer: Bloodhound.tokenizers.obj.whitespace('value'),
			queryTokenizer: Bloodhound.tokenizers.whitespace,
			remote: {
				url: jQuery('base').attr('href') + 'index.php?route=extension/module/msmart_search/autocomplete&phrase=%QUERY',
				wildcard: '%QUERY',
				rateLimitWait: 500,
				cache: mode == 'tabs' ? false : true,
				prepare: function( query, settings ){
					settings.dataType = 'text';
					settings.url = settings.url.replace('%QUERY', encodeURIComponent(query));
					
					tabs = {};
					
					$input.closest('.msmart-search-live-filter').find('.tt-input').trigger('change.shva');
					
					return settings;
				},
				transform: function( response ) {
					var $tmp = $('<tmp>')
						.html( response ),
						payload = $tmp.find('#mss-response').html(),
						json = { results: [], lang: {} };
					
					try {
						json = payload ? JSON.parse( base64_decode( payload ) ) : JSON.parse( response );
					} catch(e) {}
					
					lang = json.lang || {};
					
					$btnMore.html( lang.text_button_view_all || 'Pogledajte sve rezultate' );
					
					return json.results || [];
				}
			}
		}),
		templates: {
			suggestion: function( data ){
				var out = '',
					html = '';
				
				if( mode == 'standard' ) {
					if( data.img ) {
						out += '<td width="' + data.img_w + '"><img src="' + data.img + '" width="' + data.img_w + '" height="' + data.img_h + '" alt="" /></td>';
					}

					out += '<td>';
					out += data.name;
					out += '<div class="clearfix"></div>';

					if( data.description ) {
						out += '<small>' + data.description + '</small>';
						out += '<div class="clearfix"></div>';
					}

					if( data.manufacturer ) {
						out += '<small class="pull-left">' + data.manufacturer + '</small>';
					}

					if( data.model ) {
						out += '<small class="pull-right">' + data.model + '</small>';
					}

					out += '</td>';

					if( data.price ) {
						out += '<td class="mslf-price" width="1%">';

						if( data.special ) {
							out += data.special;
							out += '<br />';
							out += '<strike>';
						}

						out += data.price;

						if( data.special ) {
							out += '</strike>';
						}

						out += '</td>';
					}

					return '<div data-type="' + data.type + '"><table><tr>' + out + '</tr></table></div>';
				}
				
				out += '<div class="mssl-product-item-list col-xs-' + ( 12 / parseInt( config.product_columns ) ) + '">';
				out += '<a href="' + data.url + '">';
				out += '<img src="' + data.img + '" width="' + data.img_w + '" height="' + data.img_h + '" alt="" />';
				out += '<span class="inline-block product-name">' + data.name + '</span>';
				
				if( data.price ) {
					out += '<span class="product-price">';
					out += data.special ? '<strike>' : '';
					out += data.price;
					out += data.special ? '</strike>' : '';
					out += '</span>';
					
					if( data.special ) {
						out += '<span class="product-special-price">' + data.special + '</span>';
					}
				}
				
				out += '</a>';
				out += '</div>';
				
				html = '<div class="' + ( typeof tabs[data.extra_phrase||''] == 'undefined' ? '' : 'hide' ) + '" data-type="' + data.type + '" data-extra-phrase="' + ( data.extra_phrase||'' ).replace(/"/g, '&quot;') + '">';
				html += (data.extra_phrase||keyword) + '<div class="hide">' + out + '</div>';
				html += '</div>';
				
				tabs[data.extra_phrase||''] = true;
				
				return html;
			}
		},
		limit: config.limit * 10
	}).on('keydown', function(e){
		if( e.keyCode == 13 && !$(this).parent().find('.tt-cursor').length ) {
			e.preventDefault();
			keyword = $(this).val();
			addphrase();
			location = url_search( keyword );
		}
	}).on('keyup', function(){
		var _this = $(this);
		
		keyword = _this.val();
		
		if( keyword == '' && config.show_loading_icon ) {
			_this.parent().find('.mslf-loading').remove();
		}
	}).bind('typeahead:select', function(e, suggestion){
		addphrase();
		
		setTimeout(function(){
			if( mode == 'tabs' ) {
				document.location = url_search( suggestion.extra_phrase||keyword );
			} else {
				document.location = suggestion.url.replace(/&amp;/g, '&');
			}
		},300);
		
		if( mode == 'tabs' ) {
			setInterval(function(){
				$input.closest('.msmart-search-live-filter').find('.tt-input').val('');
			},10);
		}
	}).bind('typeahead:asyncrequest', function(){
		if( config.show_loading_icon ) {
			if( ! $(this).parent().find('.mslf-loading').length ) {
				$(this).parent().prepend('<div class="mslf-loading"></div>');
			}
		}
	}).bind('typeahead:asyncreceive', function(a){
		var _this = $(this),
			$wrapper = _this.parent(),
			$datasetProductsCurrent = $wrapper.find('.tt-dataset-products').first(),
			$datasetCategoriesCurrent = $wrapper.find('.tt-dataset-categories').first(),
			$btnMoreCurrent = $wrapper.find('.mss-button-more').first();
		
		if( config.show_loading_icon ) {
			$wrapper.find('.mslf-loading').remove();
		}
		
		setTimeout(function(){
			$wrapper.find('.tt-menu').each(function(){
				if( ! $(this).is(':visible') && $(this).find('.tt-suggestion').length ) {
					$(this).css('display', 'block');
				}
			});
		}, 50);
		
		if( $datasetCategoriesCurrent.length && config.enabled_categories == '1' ) {
			$datasetCategoriesCurrent.html('');
		}
		
		if( mode == 'standard' && ( config.enabled_categories == '1' || config.show_header || config.show_button_view_all == '1' ) ) {
			setTimeout(function(){
				var maxResults = parseInt(config.limit, 10) || 10,
					products = $datasetProductsCurrent.find('[data-type=product]').length,
					categories = $datasetProductsCurrent.find('[data-type=category]').length;
				
				if( categories ) {
					$datasetProductsCurrent.find('[data-type=category]').each(function(){
						$datasetCategoriesCurrent.append( $(this) );
					});
				}

				var $productsCurrent = $datasetProductsCurrent.find('.tt-suggestion'),
					$categoriesCurrent = $datasetCategoriesCurrent.find('.tt-suggestion'),
					remainingCategories = Math.max(maxResults - $productsCurrent.length, 0);

				$productsCurrent.slice(maxResults).remove();
				$categoriesCurrent.slice(remainingCategories).remove();

				products = $datasetProductsCurrent.find('.tt-suggestion').length;
				categories = $datasetCategoriesCurrent.find('.tt-suggestion').length;
				
				if( config.show_header ) {
					$wrapper.find('.mss-header-products')[products?'removeClass':'addClass']('hide').text( lang.text_products );
				}
				
				if( config.show_header_categories ) {
					$wrapper.find('.mss-header-categories')[categories?'removeClass':'addClass']('hide').text( lang.text_categories );
				}
				
				$datasetProductsCurrent[products?'removeClass':'addClass']('hide');
				
				if( config.show_button_view_all == '1' ) {
					$btnMoreCurrent.html( lang.text_button_view_all || 'Pogledajte sve rezultate' );
					$btnMoreCurrent[products?'removeClass':'addClass']('hide');
				}
				
				if( config.enabled_categories == '1' ) {
					$datasetCategoriesCurrent[categories?'removeClass':'addClass']('hide');
				}
			},10);
		} else if( mode == 'tabs' ) {
			setTimeout(function(){
				$datasetProducts = $('<div class="mslf-product-list" style="height:' + (config.max_height_tabs||430)+'px">');

				$(config.input_selector).parent().find('.tt-dataset > div').mouseover(function(){
					var phrase = $(this).attr('data-extra-phrase')||'';
						
					$datasetProducts.html('');
					
					if( config.show_header ) {
						$datasetProducts.html('<h3>' + lang.text_top_results + ' "' + (phrase||keyword) + '"' + '</h3>');
					}

					$(config.input_selector).parent().find('.tt-dataset > [data-extra-phrase] > div').each(function(){
						if( $(this).parent().attr('data-extra-phrase') == phrase ) {
							$datasetProducts.append( $(this).html() );
						}
					});
				});
				
				$(config.input_selector).parent().find('.tt-dataset > div:first').trigger('mouseover');

				$(config.input_selector).parent().find('.tt-dataset').append( $datasetProducts );
			},10);
		}
	});
	
	if( mode == 'standard' ) {
		$input.parent().each(function(){
			var $wrapper = $(this),
				$datasetProductsLocal = $wrapper.find('.tt-dataset').first().addClass('tt-dataset-products'),
				$datasetCategoriesLocal = null,
				$btnMoreLocal = $btnMore.clone();
			
			$datasetProductsLocal.css('max-height', config.max_height + 'px');
			
			if( config.enabled != '1' ) {
				$datasetProductsLocal.addClass('hide');
			} else if( config.show_header ) {
				$datasetProductsLocal.before( '<h3 class="mss-header mss-header-products">' );
			}

			if( config.enabled_categories == '1' ) {
				$datasetCategoriesLocal = $('<div class="tt-dataset tt-dataset-categories">').css('max-height', config.max_height + 'px');
				
				if( config.display_categories_first == '1' ) {
					$datasetCategoriesLocal.prependTo( $datasetProductsLocal.parent() );
				} else {
					$datasetCategoriesLocal.appendTo( $datasetProductsLocal.parent() );
				}

				if( config.show_header_categories ) {
					$datasetCategoriesLocal.before( '<h3 class="mss-header mss-header-categories">' );
				}
			}

			if( config.show_button_view_all == '1' ) {
				$btnMoreLocal.appendTo( $wrapper.find('.tt-menu') )
					.attr('href', url_search( $wrapper.find('.tt-input').val() ) );

				$wrapper.find('.tt-input').bind('keyup change.shva', function(){
					$btnMoreLocal.attr( 'href', url_search( $(this).val() ) );
				});
			}
		});
	} else {
		$input.parent().find('.tt-dataset').css('height', (config.max_height_tabs||430)+'px');
	}
	
	keyword = $(".msmart-search-live-filter .tt-input").val();
	
	$('.msmart-search-live-filter').click(function(e) {
		e.stopPropagation();
	});
	
	/* Search */
	$('#search').find('button').off('click').on('click', function() {
		location = url_search( $('header #search input[name=search]').val() );
	});

	$('#search input[name=search]').on('keydown', function(e) {
		if( e.keyCode == 13 ) {
			$('header #search').find('button').trigger('click');
		}
	});
});
