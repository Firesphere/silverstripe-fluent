<?php

/**
 * Home page controller for multiple locales
 * 
 * @package fluent
 * @author Damian Mooyman <damian.mooyman@gmail.com>
 */
class FluentRootURLController extends RootURLController {
	
	public function handleRequest(SS_HTTPRequest $request, DataModel $model = null) {
		
		self::$is_at_root = true;
		$this->setDataModel($model);
		
		// Check locale, redirecting to locale root if necessary
		$locale = $request->param('FluentLocale');
		if(empty($locale)) {
			
			// Check browser headers
			$locale = Fluent::detect_browser_locale();
			
			// Fallback to default detection (which falls back to the default locale)
			if(empty($locale)) $locale = Fluent::current_locale();
			
			$localeURL = Fluent::alias($locale);
			return $this->redirect($localeURL.'/');
		}
		
		$this->pushCurrent();
		$this->init();
		
		if(!DB::isActive() || !ClassInfo::hasTable('SiteTree')) {
			$this->response = new SS_HTTPResponse();
			$this->response->redirect(Director::absoluteBaseURL() . 'dev/build?returnURL=' . (isset($_GET['url']) ? urlencode($_GET['url']) : null));
			return $this->response;
		}
		
		$localeURL = Fluent::alias($locale);
		$request->setUrl($localeURL.'/home/');
		$request->match($localeURL.'/$URLSegment//$Action', true);
		
		$controller = new ModelAsController();
		$result = $controller->handleRequest($request, $model);
		
		$this->popCurrent();
		return $result;
	}
}
