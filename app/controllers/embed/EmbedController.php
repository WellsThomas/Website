<?php namespace uk\co\la1tv\website\controllers\embed;

use View;
use Config;
use uk\co\la1tv\website\models\Playlist;
use URL;

class EmbedController extends EmbedBaseController {

	public function getIndex($playlistId, $mediaItemId) {
	
		$playlist = Playlist::with("show", "mediaItems")->accessible()->accessibleToPublic()->find(intval($playlistId));
		if (is_null($playlist)) {
			$this->showUnavailable();
			return;
		}
		
		$currentMediaItem = $playlist->mediaItems()->accessible()->find($mediaItemId);
		if (is_null($currentMediaItem)) {
			$this->showUnavailable();
			return;
		}
		
		$title = $playlist->generateEpisodeTitle($currentMediaItem);
	
		$view = View::make("embed.player");
		$view->episodeTitle = $title;
		$view->playerInfoUri = $this->getInfoUri($playlistId, $mediaItemId);
		$view->registerViewCountUri = $this->getRegisterViewCountUri($playlistId, $mediaItemId);
		$view->registerLikeUri = $this->getRegisterLikeUri($playlistId, $mediaItemId);
		$view->loginRequiredMsg = "Please log in to our website to use this feature.";
		$view->hyperlink = URL::route('player', array($playlistId, $mediaItemId));
		
		$this->setContent($view, "player", 'LA1:TV- "' . $title . '"');
	}
	
	private function showUnavailable() {
		$this->setContent(View::make("embed.unavailable"), "unavailable", "LA1:TV- Item Unavailable");
	}
	
	private function getInfoUri($playlistId, $mediaItemId) {
		return Config::get("custom.embed_player_info_base_uri")."/".$playlistId ."/".$mediaItemId;
	}
	
	private function getRegisterViewCountUri($playlistId, $mediaItemId) {
		return Config::get("custom.embed_player_register_view_count_base_uri")."/".$playlistId ."/".$mediaItemId;
	}
	
	private function getRegisterLikeUri($playlistId, $mediaItemId) {
		return Config::get("custom.embed_player_register_like_base_uri")."/".$playlistId ."/".$mediaItemId;
	}
	
	public function missingMethod($parameters=array()) {
		// redirect /[integer]/[anything] to /index/[integer]/[anything]
		if (count($parameters) >= 1 && ctype_digit($parameters[0])) {
			return call_user_func_array(array($this, "getIndex"), $parameters);
		}
		else {
			return parent::missingMethod($parameters);
		}
	}
}