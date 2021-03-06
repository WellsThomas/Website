<?php
$nav = array(
	"dashboard"		=> array("Dashboard", Config::get("custom.admin_base_url")."/dashboard", false),
	"media"			=> array("Media", Config::get("custom.admin_base_url")."/media", false),
	"shows"			=> array("Shows", Config::get("custom.admin_base_url")."/shows", false),
	"playlists"		=> array("Playlists", Config::get("custom.admin_base_url")."/playlists", false),
	"livestreams"	=> array("Live Streams", Config::get("custom.admin_base_url")."/livestreams", false),
	"siteusers"		=> array("Site Users", Config::get("custom.admin_base_url")."/siteusers", false),
	"users"			=> array("CMS Users", Config::get("custom.admin_base_url")."/users", false),
	"apiusers"		=> array("API Users", Config::get("custom.admin_base_url")."/apiusers", false),
	"monitoring"	=> array("Monitoring", Config::get("custom.admin_base_url")."/monitoring", false)
);

// make the current page active in the nav bar
if (isset($nav[$currentNavPage])) {
	$nav[$currentNavPage][2] = true;
}

?>
@extends('layouts.home.admin.body')

@section('navbarList')
<?php foreach($mainMenuItems as $b):
	$a = $nav[$b];
?>
<li class="<?=$a[2]?"active":""?>"><a href="<?=e(URL::to($a[1]))?>"><?=e($a[0])?></a></li>
<?php endforeach; ?>
<?php if (count($moreMenuItems) > 0): ?>
<li class="dropdown">
	<a href="#" class="dropdown-toggle" data-toggle="dropdown">More <b class="caret"></b></a>
	<ul class="dropdown-menu">
		<?php foreach($moreMenuItems as $b):
			$a = $nav[$b];
		?>
		<li><a href="<?=e(URL::to($a[1]))?>"><?=e($a[0])?></a></li>
		<?php endforeach; ?>
	</ul>
</li>
<?php endif; ?>
@stop

@section('content')
<div id="main-content" class="container-fluid page-<?=$cssPageId?>">
	<?=$content?>
</div>
@stop