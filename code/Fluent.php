<?php

/**
 * Bootstrapping and configuration object for Fluet localisation
 *
 * @package fluent
 * @author Damian Mooyman <damian.mooyman@gmail.com>
 */
class Fluent extends Object {
	
	/**
	 * Determine if routes are invalid and require regeneration
	 */
	protected static function routes_invalidated() {
		
		// If flushing the application, force routes to become invalidated
		if(	!empty($_GET['flush']) || isset($_REQUEST['url'])
			&& ($_REQUEST['url'] == 'dev/build' || $_REQUEST['url'] == BASE_URL . '/dev/build')
		) {
			return true;
		}
		
		// If routes are not initialised then generate
		if(empty(self::config()->routes)) {
			return true;
		}
		
		return false;
	}
	
	/**
	 * Forces regeneration of all locale routes
	 */
	public static function regenerate_routes() {
		$routes = array();
		
		// Explicit routes
		foreach(self::config()->locales as $locale) {
			$url = self::alias($locale);
			$routes[$url.'/$URLSegment!//$Action/$ID/$OtherID'] = array(
				'Controller' => 'ModelAsController',
				'FluentLocale' => $locale
			);
			$routes[$url] = array(
				'Controller' => 'FluentRootURLController',
				'FluentLocale' => $locale
			);
		}
		
		// Default route
		$routes[''] = 'FluentRootURLController';
		
		// Set routes locally
		self::config()->routes = $routes;
		
		// Load into core routes
		Config::inst()->update('Director', 'rules', $routes);
	}
	
	/**
	 * Initialise routes
	 */
	public static function init() {
		
		// Allow route generation to be turned off
		if(!self::config()->generate_routes) return;
		
		// Determine if routes are valid
		if(!self::routes_invalidated()) return;
		
		// Regenerate routes
		self::regenerate_routes();
	}
	
	protected static $last_set_locale = null;
	
	/**
	 * Gets the current locale
	 * 
	 * @return string i18n locale code
	 */
	public static function current_locale() {
		
		$locale = null;
		
		// Check controller and current request
		if(Controller::has_curr()) {
			$controller = Controller::curr();
			$request = $controller->getRequest();

			if(self::is_frontend()) {
				// If viewing the site on the front end, determine the locale from the viewing parameters
				$locale = $request->param('FluentLocale');
			}

			if(empty($locale)) {
				// If viewing the site from the CMS, determine the locale using the session or posted parameters
				$locale = $request->requestVar('FluentLocale');
			}
		}
		
		// check session
		if(empty($locale)) $locale = Session::get('FluentLocale');
		
		// Check cookies
		if(empty($locale)) $locale = Cookie::get('FluentLocale');
		
		// Check result
		if(empty($locale)) $locale = self::default_locale();
		
		// Reset to default locale if not listed in the specified list
		$allowedLocales = self::locales();
		if(!in_array($locale, $allowedLocales)) $locale = self::default_locale();
		
		// Save locale
		Session::set('FluentLocale', $locale);
		
		// Prevent unnecessarily excessive cookie assigment
		if(!headers_sent() && self::$last_set_locale !== $locale) {
			self::$last_set_locale = $locale;
			Cookie::set('FluentLocale', $locale);
		}
		
		return $locale;
	}
	
	/**
	 * Retrieves the list of locales
	 * 
	 * @return array
	 */
	public static function locales() {
		return self::config()->locales;
	}
	
	/**
	 * Retrieves the list of locale names as an associative array
	 * 
	 * @return array
	 */
	public static function locale_names() {
		$locales = array();
		foreach (self::locales() as $locale) {
			$locales[$locale] = i18n::get_locale_name($locale);
		}
		return $locales;
	}
	
	/**
	 * Retrieves the default locale
	 * 
	 * @return string
	 */
	public static function default_locale() {
		return self::config()->default_locale;
	}
	
	/**
	 * Determines field replacement method.
	 * If viewing on the front end, blank values for a translation will be replaced with the default value.
	 * If viewing in the CMS they will be left blank for filling in.
	 * 
	 * For the sake of unit tests Fluent assumes a frontend execution environment.
	 * 
	 * @return boolean Flag indicating if the translation should act on the frontend
	 */
	public static function is_frontend() {
		if(!Controller::has_curr()) return false;
		$controller = Controller::curr();
		return 
			$controller instanceof ModelAsController
			|| $controller instanceof ContentController
			|| $controller instanceof TestRunner; // For the purposes of test, assume this is a frontend request
	}
	
	/**
	 * Helper function to check if the value given is present in any of the patterns
	 * 
	 * @param string $value
	 * @param array $patterns
	 * @return boolean
	 */
	public static function any_match($value, $patterns) {
		foreach($patterns as $pattern) {
			if(strpos($pattern, '/') === 0) {
				if(preg_match($pattern, $value)) return true;
			} else {
				if($value === $pattern) return true;
			}
		}
		return false;
	}
	
	/**
	 * Determine the alias to use for a specific locale
	 * 
	 * @param string $locale Locale in language_Country format
	 * @return string Locale in its original form, or its alias if one exists
	 */
	public static function alias($locale) {
		$aliases = self::config()->aliases;
		return empty($aliases[$locale])
			? $locale
			: $aliases[$locale];
	}
	
	/**
	 * Determine the DB field name to use for the given base field
	 * 
	 * @param string $field DB field name
	 * @param string $locale Locale to use
	 */
	public static function db_field_for_locale($field, $locale) {
		return "{$field}_{$locale}";
	}
	
	/**
	 * Installs the current locale into i18n
	 */
	public static function install_locale() {
		
		// Ensure the locale is set correctly given the designated parameters
		$locale = self::current_locale();
		i18n::set_locale($locale);
		setlocale(LC_ALL, $locale);
		
		// Get date/time formats from Zend
		require_once 'Zend/Date.php';
		i18n::set_date_format(Zend_Locale_Format::getDateFormat($locale));
		i18n::set_time_format(Zend_Locale_Format::getTimeFormat($locale));
	}
	
	/**
	 * Determines the locale best matching the given list of browser locales
	 * 
	 * @return string The matching locale, or null if none could be determined
	 */
	public static function detect_browser_locale() {
		
		// Given multiple canditates, narrow down the final result using the client's preferred languages
		$browserLanguages = $_SERVER['HTTP_ACCEPT_LANGUAGE'];
		if(empty($browserLanguages)) return null;
		
		// Explode and cleanup locales
		$browserLocales = array_map(function($input) {
			// convert from en-nz:q=0.8 format into same format as our locales 
			return preg_replace(array('/\-/', '/;.+/'), array('_', ''), $input);
		}, explode(',', $browserLanguages));
		
		// Check each requested locale against loaded locales
		foreach ($browserLocales as $browserLocale) {
			foreach (self::locales() as $locale) {
				if (stripos($locale, $browserLocale) === 0) return $locale;
			}
		}
		
		return null;
	}
}
