<?php namespace uk\co\la1tv\website\api\transformers;

use uk\co\la1tv\website\models\MediaItem;
use Config;
use Exception;

class MediaItemTransformer extends Transformer {
	
	// array where first element is the playlist, second is media item
	// the playlist can be null, meaning anything that requires this will be omitted from the response
	public function transform($mediaItemAndPlaylist, array $options) {	
		if (count($mediaItemAndPlaylist) !== 2) {
			throw(new Exception("mediaItemAndPlaylist invalid."));
		}
		$playlist = $mediaItemAndPlaylist[0];
		$mediaItem = $mediaItemAndPlaylist[1];
		
		$mediaItemVideo = $mediaItem->videoItem;
		$mediaItemLiveStream = $mediaItem->liveStreamItem;
		
		$scheduledPublishTime = $mediaItem->scheduled_publish_time->timestamp;
		
		$coverArtUris = null;
		if (!is_null($playlist)) {
			$coverArtResolutions = Config::get("imageResolutions.coverArt");
			
			$coverArtUris = [
				"thumbnail"		=> $playlist->getMediaItemCoverArtUri($mediaItem, $coverArtResolutions['thumbnail']['w'], $coverArtResolutions['thumbnail']['h']),
				"full"			=> $playlist->getMediaItemCoverArtUri($mediaItem, $coverArtResolutions['full']['w'], $coverArtResolutions['full']['h']),
			];
		}
			
		$minNumberOfViews = Config::get("custom.min_number_of_views");
		$viewCountTotal = 0;
		$vodViewCount = !is_null($mediaItemVideo) ? intval($mediaItemVideo->getViewCount()) : null;
		$streamViewCount = !is_null($mediaItemLiveStream) ? intval($mediaItemLiveStream->getViewCount()) : null;
		if (!is_null($vodViewCount)) {
			$viewCountTotal += $vodViewCount;
		}
		if (!is_null($streamViewCount)) {
			$viewCountTotal += $streamViewCount;
		}
		if ($viewCountTotal < $minNumberOfViews) {
			// the combined number of views is less than the amount required for it to be sent to the client
			// send null instead
			$vodViewCount = $streamViewCount = null;
		}
		
		$numLikes = $mediaItem->likes_enabled ? $mediaItem->likes()->where("is_like", true)->count() : null;
		$numDislikes = $mediaItem->likes_enabled ? $mediaItem->likes()->where("is_like", false)->count() : null;
		
		$liveStreamDetails = $vodDetails = null;		
		
		$embedDetails = [
			"iframeUrl"	=> !is_null($playlist) ? $playlist->getMediaItemEmbedUri($mediaItem) : $mediaItem->getEmbedUri()
		];
		
		$stateDefinition = null;
		if (!is_null($mediaItemLiveStream) && $mediaItemLiveStream->getIsAccessible()) {
			$stateDefinition = intval($mediaItemLiveStream->getResolvedStateDefinition()->id);
			$state = null;
			if ($stateDefinition === 1) {
				$state = "NOT_LIVE";
			}
			else if ($stateDefinition === 2) {
				$state = "LIVE";
			}
			else if ($stateDefinition === 3) {
				$state = "SHOW_OVER";
			}
			else {
				throw(new Exception("Unknown stream state."));
			}
			
			$streamEndTime = $stateDefinition === 3 && !is_null($mediaItemLiveStream->end_time) ? $mediaItemLiveStream->end_time->timestamp : null;
			$infoMsg = $stateDefinition === 1 ? $mediaItemLiveStream->information_msg : null;
			
			$liveStream = $mediaItemLiveStream->liveStream;
			$liveStreamId = !is_null($liveStream) ? intval($liveStream->id) : null;
			$streamQualities = null;
			$streamUrlData = null;
			// $liveStream can be null whilst the state being "LIVE" if there's an external stream url
			if (!is_null($liveStream) && $stateDefinition === 2) {
				$streamQualities = [];
				if ($options['showStreamUris']) {
					$streamUrlData = [];
				}
				// don't retrieve urls that support dvr
				foreach($mediaItemLiveStream->getQualitiesWithUris(array("live")) as $qualityWithUris) {
					$urls = [];
					foreach($qualityWithUris['uris'] as $a) {
						$urls[] = [
							"url"				=> $a['uri'],
							"type"				=> $a['type'],
							"supportedDevices"	=> is_null($a['supportedDevices']) ? null : explode(",", $a['supportedDevices'])
						];
					}
					$qualityInfo = [
						"id"	=> intval($qualityWithUris['qualityDefinition']->id),
						"name"	=> $qualityWithUris['qualityDefinition']->name
					];
					
					$streamQualities[] = $qualityInfo;
					if ($options['showStreamUris']) {
						$streamUrlData[] = [
							"quality"	=> $qualityInfo,
							"urls"		=> $urls
						];
					}
				}
			}
			
			$liveStreamDetails = [
				"state"					=> $state,
				"viewCount"				=> $streamViewCount,
				"beingRecorded"			=> (boolean) $mediaItemLiveStream->being_recorded,
				"externalStreamPageUrl"	=> $mediaItemLiveStream->external_stream_url,
				"streamEndTime"			=> $streamEndTime,
				"informationMsg"		=> $infoMsg, // only accessible when the stream is in NOT_LIVE mode
				"qualities"				=> $streamQualities,
				"hasDvr"				=> $mediaItemLiveStream->hasDvrRecording(),
				"urlData"				=> $streamUrlData,
				"liveStreamId"			=> $liveStreamId
			];
		}
		
		if (!is_null($mediaItemVideo) && $mediaItemVideo->getIsAccessible()) {
			$vodAvailable = $mediaItemVideo->getIsLive();
			$vodChapters = null;
			if ($vodAvailable) {
				$vodChapters = array();
				foreach($mediaItemVideo->chapters()->orderBy("time", "asc")->orderBy("title", "asc")->get() as $b=>$a) {
					$vodChapters[] = array(
						"title"		=> $a->title,
						"time"		=> intval($a->time)
					);
				}
			}
			$vodTimeRecorded = null;
			if (!is_null($mediaItemVideo->time_recorded)) {
				$vodTimeRecorded = $mediaItemVideo->time_recorded->timestamp;
			}
			else if ($stateDefinition === 3) {
				// has live stream and show over.
				// this must be the recording of that so set to the match the publish time
				$vodTimeRecorded = $scheduledPublishTime;
			}
			
			$vodQualities = null;
			$vodUrlData = null;
			$vodDuration = null;
			if ($mediaItemVideo->getIsLive()) {
				$vodDuration = $mediaItemVideo->getDuration(); 
				$vodQualities = [];
				if ($options['showVodUris']) {
					$vodUrlData = [];
				}
				foreach($mediaItemVideo->getQualitiesWithUris() as $qualityWithUris) {
					$urls = [];
					foreach($qualityWithUris['uris'] as $a) {
						$urls[] = [
							"url"				=> $a['uri'],
							"type"				=> $a['type'],
							"supportedDevices"	=> is_null($a['supportedDevices']) ? null : explode(",", $a['supportedDevices'])
						];
					}
					$qualityInfo = [
						"id"	=> intval($qualityWithUris['qualityDefinition']->id),
						"name"	=> $qualityWithUris['qualityDefinition']->name
					];
					
					$vodQualities[] = $qualityInfo;
					if ($options['showVodUris']) {
						$vodUrlData[] = [
							"quality"	=> $qualityInfo,
							"urls"		=> $urls
						];
					}
				}
			}

			$processingCompleted = false;
			$processingError = false;
			$processingPercentage = null;
			$processingMessage = null;
			$sourceFile = $mediaItemVideo->sourceFile;
			if (!is_null($sourceFile)) {
				$processingCompleted = $sourceFile->getFinishedProcessing();
				if (!$processingCompleted) {
					$processInfo = $sourceFile->getProcessInfo();
					$processingPercentage = $processInfo["percentage"];
					$processingMessage = $processInfo["msg"];
					$processingError = $processInfo["state"] === 2;
				}
			}

			$vodDetails = [
				"available"		=> $vodAvailable,
				"timeRecorded"	=> $vodTimeRecorded,
				"duration"		=> $vodDuration,
				"processing"	=> [
					"completed"		=> $processingCompleted,
					"percentage"	=> $processingPercentage,
					"message"		=> $processingMessage,
					"error"			=> $processingError
				],
				"chapters"		=> $vodChapters,
				"viewCount"		=> $vodViewCount,
				"qualities"		=> $vodQualities,
				"urlData"		=> $vodUrlData
			];
		}
		
		$responseData = [
			"id"				=> intval($mediaItem->id),
			"name"				=> $mediaItem->name,
			"description"		=> $mediaItem->description,
			"coverArtUrls"		=> $coverArtUris,
			"scheduledPublishTime"	=> $scheduledPublishTime,
			"liveStream"		=> $liveStreamDetails,
			"vod"				=> $vodDetails,
			"viewCount"			=> $viewCountTotal,
			"numLikes"			=> $numLikes,
			"numDislikes"		=> $numDislikes,
			"embed"				=> $embedDetails,
			"timeUpdated"		=> $mediaItem->updated_at->timestamp
		];
		if (!is_null($playlist)) {
			$responseData['siteUrl'] = $playlist->getMediaItemUri($mediaItem);
			$responseData['episodeNumber'] =  $playlist->getEpisodeNumber($mediaItem);
		}
		else {
			$responseData['siteUrl'] = $mediaItem->getUri();
		}
		return $responseData;
	}
	
}