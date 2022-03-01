/* CUSTOM FONTS */

/*
Project: 		Code Snippet: Load custom fonts and inject to Oxygen
Version:		2.2.4
Description: 	en: https://www.altmann.de/en/blog-en/code-snippet-integrate-custom-fonts-into-oxygen-en/
				de: https://www.altmann.de/blog/code-snippet-eigene-schriftarten-in-oxygen-integrieren/
Author:			Matthias Altmann (https://www.altmann.de/)
Copyright:		© 2020, Matthias Altmann




Get Started:
1) Create a new directory wp-content/uploads/fonts
2) Upload your custom fonts (eot, otf, svg, ttf, woff, woff2) to this fonts directory. Sub-directories allowed.
3) This Code Snippet finds all font files, injects them to Oxygen and emits related CSS to frontend.

Font Names:
- The file name of the font files will be used as font name in Oxygen.
- If the file name of the font file contains a numeric weight, common tags for font weight (light, regular, bold, ...)
  or style (italic) those tags will not be used for the font name but instead translated to proper CSS properties.
- If multiple font file formats for one font are found, they will all be offered to the browser.

Configuration:
Find the comment ===== CONFIG ===== in the code to read about a few configuration variables.
Please only change if you understand the meaning.

Performance:
(measurements on iMac i9 3.6 GHz, MAMP, PHP 7.4.2, about 30 font files in subdirectories)
- Scanning for fonts: 	ø 0.0015 sec.
- Emitting CSS: 		ø 0.0002 sec.


Version History:
Date		Version		Description
--------------------------------------------------------------------------------------------------------------
2020-04-10	1.0.0		Initial Release for customer project
2020-09-15	2.0.0		Improved version
						- Finds all font files (eot, otf, svg, ttf, woff, woff2) in directory wp-uploads/fonts/
						- Optionally recursive
						- Takes font name from file name
						- Emits optimized CSS with alternative font formats
						- Special handling for EOT for Internet Explorer
2020-09-16	2.1.0		New features:
						- Detection of font weight and style from file name
						Bug fixes:
						- EOT: Typo in extension detection
						- EOT: Missing quote in style output
2020-10-03 	2.1.1		Bug fix:
						- Handle empty fonts folder correctly. (Thanks to Mario Peischl for reporting!)
						- Corrected title and file name (typo "cutsom") of Code Snippet
2020-11-23	2.2.0		New features:
						- Detection of font weight from number values
						- CSS now contains font-display:swap;
2020-11-24	2.2.1		New features:
						- Shortcode [maltmann_custom_font_test] for listing all custom fonts with their weights
						  and styles
						Changes:
						- Fonts are now sorted alphabetically for e.g. CSS output
						- Added more request rules to skipping code execution when not needed
2020-11-25	2.2.2		New features:
						- Partial support for fonts with variable weights, detected by "VariableFont" in
						  filename. CSS output as font-weight:100 900;
2020-11-25	2.2.3		Changes:
						- In Oxygen font selectors the custom fonts are now displayed in lightblue
						  to distinguish from default, websafe and Google Fonts
2020-11-27	2.2.4		Bug fix:
						- Corrected typo in variable name (2 occurrences) that could lead to repeated search
						  for font files. (Thanks to Viorel Cosmin Miron for reporting!)
--------------------------------------------------------------------------------------------------------------
*/


if (	!class_exists('ECF_Plugin')
	&&	!wp_doing_ajax() 														// skip for ajax
	&& 	!wp_doing_cron()														// skip for cron
	&& 	(!array_key_exists('heartbeat',$_REQUEST))								// skip for hearbeat
	&& 	(@$_REQUEST['action'] != 'set_oxygen_edit_post_lock_transient')			// skip for Oxygen editor lock
	&&  (@$_SERVER['REQUEST_URI'] != '/favicon.ico')							// skip for favicon
	) :
	// create a primitive ECF_Plugin class if plugin "Elegant Custom Fonts" is not installed
	class ECF_Plugin {
		// ===== CONFIG =====
		public static $recursive 	= true; 	// set this to false for flat or true for recursive file scan
		public static $parsename 	= true; 	// set this to true for parsing font weight and style from file name
		public static $timing		= false; 	// write timing to wordpress debug.log if WP_DEBUG enabled
		public static $debug		= false; 	// write debug info (a lot!) to wordpress debug.log if WP_DEBUG enabled

		// ===== INTENAL =====
		private static $fonts 				= null;	// Will be populated with the found fonts and related files
		private static $fonts_details_cache	= [];	// cache for already parsed font details

		// -----------------------------------------------------------------------------------
		static function parse_font_name($name) {
			// already in cache?
			if (array_key_exists($name,self::$fonts_details_cache)) {return self::$fonts_details_cache[$name];}

			$retval = (object)['name'=>$name, 'weight'=>400, 'style'=>'normal'];
			if (!self::$parsename) {return $retval;}
			$st = microtime(true);
			$weights = (object)[ // must match from more to less specific !!
				// more specific
				200 => '/\-?(200|((extra|ultra)\-?light))/i',
				800 => '/\-?(800|((extra|ultra)\-?bold))/i',
				600 => '/\-?(600|([ds]emi\-?bold))/i',
				// less specific
				100 => '/\-?(100|thin)/i',
				300 => '/\-?(300|light)/i',
				400 => '/\-?(400|normal|regular)/i',
				500 => '/\-?(500|medium)/i',
				700 => '/\-?(700|bold)/i',
				900 => '/\-?(900|black|heavy)/i',
				'var' => '/\-?(VariableFont)/i',
			];
			if (WP_DEBUG && self::$debug) {error_log(sprintf('Custom Fonts parse_font_name("%s")', $retval->name));}
			$count = 0;
			// detect & cut style
			$new_name = preg_replace('/\-?italic/i', '', $retval->name, -1, $count);
			if ($new_name && $count) {
				$retval->name = $new_name;
				$retval->style = 'italic';
				if (WP_DEBUG && self::$debug) {error_log(sprintf('Custom Fonts parse_font_name() detected italic, new name: "%s"', $retval->name));}
			}
			// detect & cut weight
			foreach ($weights as $weight => $pattern) {
				$new_name = preg_replace($pattern, '', $retval->name, -1, $count);
				if ($new_name && $count) {
					$retval->name = $new_name;
					$retval->weight = $weight;
					if (WP_DEBUG && self::$debug) {error_log(sprintf('Custom Fonts parse_font_name() detected weight %d, new name: "%s"', $weight, $retval->name));}
					break;
				}
			}
			// variable font: detect & cut specifica
			if ($retval->weight == 'var') {
				$retval->name = preg_replace('/_(opsz,wght|opsz|wght)$/i', '', $retval->name);
			}

			if (WP_DEBUG && self::$debug) {error_log(sprintf('Custom Fonts parse_font_name() retval: [name:"%s", weigh:%d, style:%s]', $retval->name, $retval->weight, $retval->style));}
			// store to cache
			self::$fonts_details_cache[$name] = $retval;
			$et = microtime(true);
			if (WP_DEBUG && self::$debug) {error_log(sprintf('Custom Fonts parse_font_name() Timing: %.5f sec.',$et-$st));}
			return $retval;
		}
		// -----------------------------------------------------------------------------------
		static function find_fonts() {
			$st = microtime(true);
			if (isset(self::$fonts)) return;
			self::$fonts = [];
			$fonts_dir_info = wp_get_upload_dir();
			$fonts_basedir = $fonts_dir_info['basedir'].'/fonts';
			$fonts_baseurl = $fonts_dir_info['baseurl'].'/fonts';
			if (!file_exists($fonts_basedir)) return;
			// property $recursive either recursive or flat file scan
			if (self::$recursive) {
				// recursive scan for font files (including subdirectories)
				$directory_iterator = new RecursiveDirectoryIterator($fonts_basedir);
				$file_iterator = new RecursiveIteratorIterator($directory_iterator);
			} else {
				// flat scan for font files (no subdirectories)
				$file_iterator = new FilesystemIterator($fonts_basedir);
			}
			// loop through files and collect font files
			$font_splfiles = [];
			foreach( $file_iterator as $file) {
    			if (in_array(strtolower($file->getExtension()), ['eot','otf','svg','ttf','woff','woff2'])) {
					$font_splfiles[] = $file;
    			}
			}
			// collect font definitions
			foreach ($font_splfiles as $font_splfile) {
				$font_ext = $font_splfile->getExtension();
				$font_details = self::parse_font_name($font_splfile->getbasename('.'.$font_ext));
				$font_name = $font_details->name;
				$font_weight = $font_details->weight;
				$font_style = $font_details->style;
				$font_path = str_replace($fonts_basedir,'',$font_splfile->getPath());
				// encode every single path element since we might have spaces or special chars
				$font_path = implode('/',array_map('rawurlencode',explode('/',$font_path)));
				$font_baseurl = str_replace($fonts_basedir, $fonts_baseurl, $font_splfile->getFilename());
				// create entry for this font name
				if (!array_key_exists($font_name,self::$fonts)) {self::$fonts[$font_name] = [];}
				// create entry for this font weight/style
				if (!array_key_exists($font_weight.'/'.$font_style,self::$fonts[$font_name])) {self::$fonts[$font_name][$font_weight.'/'.$font_style] = [];}
				// store font details for this file
				self::$fonts[$font_name][$font_weight.'/'.$font_style][$font_ext] = $fonts_baseurl . $font_path . '/' . rawurlencode($font_splfile->getBasename());
			}
			ksort(self::$fonts, SORT_NATURAL | SORT_FLAG_CASE);
			if (WP_DEBUG && self::$debug) {error_log(sprintf('Custom Fonts find_fonts() font: %s',print_r(self::$fonts,true)));}
			$et = microtime(true);
			if (WP_DEBUG && self::$timing) {error_log(sprintf('Custom Fonts find_fonts() Timing: %.5f sec.',$et-$st));}
		}
		// -----------------------------------------------------------------------------------
		static function get_font_families() {
			if (!isset(self::$fonts)) self::find_fonts();
			$st = microtime(true);
			$font_family_list = [];
			foreach (array_keys(self::$fonts) as $font_name) {
				$font_family_list[] = $font_name;
			}
			$et = microtime(true);
			if (WP_DEBUG && self::$timing) {error_log(sprintf('Custom Fonts get_font_families() Timing: %.5f sec.',$et-$st));}
			return $font_family_list;
		}
		// -----------------------------------------------------------------------------------
		// we call this function to get font definitions for emitting required files
		static function get_font_definitions() {
			return self::$fonts;
		}
	}
	// pre-fill font definitions
	ECF_Plugin::get_font_families();



	add_action( 'wp_footer', function () {
		// emit CSS for fonts in footer
		$st = microtime(true);
		$style = '';
		$font_defs = ECF_Plugin::get_font_definitions();
		ksort($font_defs, SORT_NATURAL | SORT_FLAG_CASE);
		foreach ($font_defs as $font_name => $font_details) {
			ksort($font_details);
			foreach ($font_details as $weight_style => $file_list) {
				list ($font_weight ,$font_style) = explode('/',$weight_style);

				if ($font_weight == 'var') {
					$font_weight_output = '100 900';
				} else {
					$font_weight_output = $font_weight;
				}
				$style .= 	'@font-face{'.PHP_EOL.
							'  font-family:"'.$font_name.'";'.PHP_EOL.
							'  font-weight:'.$font_weight_output.';'.PHP_EOL.
							'  font-style:'.$font_style.';'.PHP_EOL;
							// .eot needs special handling for IE9 Compat Mode
				if (array_key_exists('eot',$file_list)) {$style .= '  src:url("'.$file_list['eot'].'");'.PHP_EOL;}
				$urls = [];
				foreach ($file_list as $font_ext => $font_url) {
					$format = '';
					switch ($font_ext) {
						case 'eot': $format = 'embedded-opentype'; break;
						case 'otf': $format = 'opentype'; break;
						case 'ttf': $format = 'truetype'; break;
						// others have same format as extension (svg, woff, woff2)
						default:	$format = strtolower($font_ext);
					}
					if ($font_ext == 'eot') {
						// IE6-IE8
						$urls[] = 'url("'.$font_url.'?#iefix") format("'.$format.'")';
					} else {
						$urls[] = 'url("'.$font_url.'") format("'.$format.'")';
					}
				}
				$style .= '  src:' . join(','.PHP_EOL.'      ',$urls) . ';'.PHP_EOL;
				$style .= '  font-display: swap;'.PHP_EOL;
				$style .= '}'.PHP_EOL;
			}
		}
		$style .= 'div.oxygen-select-box-option.ng-binding.ng-scope[ng-repeat*="elegantCustomFonts"] {color:lightblue;}';

		if (WP_DEBUG && ECF_Plugin::$debug) {error_log(sprintf('Custom Fonts style: %s', $style));}
		// miminize string if debug mode is disabled (= production system).
		if (!ECF_Plugin::$debug) {
			$style = preg_replace('/\r?\n */','',$style);
		}
		$et = microtime(true);
		if (WP_DEBUG && ECF_Plugin::$timing) {error_log(sprintf('Custom Fonts style emitter Timing: %.5f sec.',$et-$st));}
		echo '<style id="maltmann-custom-font">'.$style.'</style>';
	} );


	// define a shortcode for testing custom fonts (listing all fonts with their weights and styles)
	add_action( 'init', function(){
		add_shortcode('maltmann_custom_font_test', 'maltmann_custom_font_test');
		if (!function_exists('maltmann_custom_font_test')) {
			function maltmann_custom_font_test($atts){
				$output = '<h2>Custom Font Test</h2>';
				foreach ( ECF_Plugin::get_font_definitions() as $font_name => $font_details) {
					$output .= sprintf('<h3 style="font-family:\'%1$s\';">%1$s</h3>',$font_name);
					ksort($font_details);
					foreach ($font_details as $weight_style => $file_list) {
						list ($font_weight,$font_style) = explode('/',$weight_style);
						if ($font_weight == 'var') {
							foreach ([100,200,300,400,500,600,700,800,900] as $font_weight) {
								$output .= sprintf('<span style="font-family:\'%1$s\'; font-weight:%2$d;font-style:%3$s">%1$s %2$d %3$s</span><br/>',$font_name, $font_weight, $font_style);
							}
						} else {
							$output .= sprintf('<span style="font-family:\'%1$s\'; font-weight:%2$d;font-style:%3$s">%1$s %2$d %3$s</span><br/>',$font_name, $font_weight, $font_style);
						}
					}
				}
				return $output;
			}
		}
	});


endif;
