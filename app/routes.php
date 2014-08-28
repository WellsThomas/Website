<?php

/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
|
| Here is where you can register all of the routes for an application.
| It's a breeze. Simply tell Laravel the URIs it should respond to
| and give it the Closure to execute when that URI is requested.
|
*/

$p = "uk\\co\\la1tv\\website\\controllers\\";
$fP = "uk\\co\\la1tv\\website\\filters\\";

Route::group(array('before' => 'csrf'), function() use(&$p, &$fP) {

	Route::controller('/admin/login', $p.'home\admin\login\LoginController');
	Route::controller('/admin/upload', $p.'home\admin\upload\UploadController');

	Route::group(array('before' => 'auth'), function() use(&$p, &$fP) {
		Route::controller('/admin/dashboard', $p.'home\admin\dashboard\DashboardController');
		Route::controller('/admin/media', $p.'home\admin\media\MediaController');
		Route::controller('/admin/series', $p.'home\admin\series\SeriesController');
		Route::controller('/admin/playlists', $p.'home\admin\playlists\PlaylistsController');
		Route::controller('/admin/livestreams', $p.'home\admin\livestreams\LiveStreamsController');
		Route::controller('/admin/live-stream-qualities', $p.'home\admin\liveStreamQualities\LiveStreamQualitiesController');
		Route::controller('/admin/siteusers', $p.'home\admin\siteUsers\SiteUsersController');
		Route::controller('/admin/users', $p.'home\admin\users\UsersController');
		Route::controller('/admin/monitoring', $p.'home\admin\monitoring\MonitoringController');
		Route::controller('/admin/permissions', $p.'home\admin\permissions\PermissionsController');
		Route::controller('/admin', $p.'home\admin\AdminController');
	});

	// make upload controller also accessible at /upload
	Route::controller('/upload', $p.'home\admin\upload\UploadController');
	Route::controller('/', $p.'home\HomeController');
});