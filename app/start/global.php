<?php
use Illuminate\Cache\RedisStore;
use uk\co\la1tv\website\extensions\cache\SynchronizedRepository;
use uk\co\la1tv\website\extensions\cache\ImprovedRedisStore;

/*
|--------------------------------------------------------------------------
| Register The Laravel Class Loader
|--------------------------------------------------------------------------
|
| In addition to using Composer, you may use the Laravel class loader to
| load your controllers and models. This is useful for keeping all of
| your classes in the "global" namespace without Composer updating.
|
*/

ClassLoader::addDirectories(array(

	app_path().'/commands',
	app_path().'/controllers',
	app_path().'/models',
	app_path().'/database/seeds',

));

/*
|--------------------------------------------------------------------------
| Application Error Logger
|--------------------------------------------------------------------------
|
| Here we will configure the error logger setup for the application which
| is built on top of the wonderful Monolog library. By default we will
| build a basic log file setup which creates a single file for logs.
|
*/

Log::useFiles(storage_path().'/logs/laravel.log');

/*
|--------------------------------------------------------------------------
| Application Error Handler
|--------------------------------------------------------------------------
|
| Here you may handle any errors that occur in your application, including
| logging them or displaying custom views for specific errors. You may
| even register several error handlers to handle different types of
| exceptions. If nothing is returned, the default error view is
| shown, which includes a detailed stack trace during debug.
|
*/

App::error(function(Exception $exception, $code)
{
	Log::error($exception);
});

App::error(function(Illuminate\Session\TokenMismatchException $exception) {
	return Response::make("CSRF error.", 500);
});

/*
|--------------------------------------------------------------------------
| Maintenance Mode Handler
|--------------------------------------------------------------------------
|
| The "down" Artisan command gives you the ability to put an application
| into maintenance mode. Here, you will define what is displayed back
| to the user if maintenance mode is in effect for the application.
|
*/

App::down(function() {
	return DebugHelpers::generateMaintenanceModeResponse();
});

if (App::environment() === "production") {
	DB::connection()->disableQueryLog();
}

// add the redisSynchronized cache driver
Cache::extend('redisSynchronized', function($app) {
	$redis = $app['redis'];
	return new SynchronizedRepository(new ImprovedRedisStore($redis, $app['config']['cache.prefix']));
});

// determine if degraded mode should be enabled
if (!Redis::get("fileStoreAvailable") && App::environment() !== "local") {
	// automatically enable if filestore not accessible
	// this key in redis is set in the CheckFileStoreAvailability command
	Config::set('degradedService.enabled', true);
}

if (Config::get('degradedService.enabled')) {
	// if degradedService is enabled disable search (thumbnail urls incorrect)
	Config::set('search.enabled', false);
}

/*
|--------------------------------------------------------------------------
| Require The Filters File
|--------------------------------------------------------------------------
|
| Next we will load the filters file for the application. This gives us
| a nice separate location to store our route and application filter
| definitions instead of putting them all in the main routes file.
|
*/

require app_path().'/filters.php';


require app_path().'/events.php';
