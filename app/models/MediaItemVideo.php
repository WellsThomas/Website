<?php namespace uk\co\la1tv\website\models;

use FormHelpers;

class MediaItemVideo extends MyEloquent {

	protected $table = 'media_items_video';
	protected $fillable = array('time_recorded', 'enabled', 'name', 'description');
	protected $appends = array("time_recorded_for_input");
	
	public function mediaItem() {
		return $this->belongsTo(self::$p.'MediaItem', 'media_item_id');
	}
	
	public function sourceFile() {
		return $this->belongsTo(self::$p.'File', 'source_file_id');
	}
	
	public function getTimeRecordedForInputAttribute() {
		if (is_null($this->time_recorded)) {
			return null;
		}
		return FormHelpers::formatDateForInput($this->time_recorded->timestamp);
	}
	
	// returns the uris to the different renders of the video
	public function getUrisWithQualities() {
		
		$sourceFile = $this->sourceFile;
		
		if (is_null($sourceFile) || !$sourceFile->getShouldBeAccessible()) {
			return array();
		}
	
		$renders = $sourceFile->renderFiles;
		$uris = array();
		$positions = array();
		foreach($renders as $a) {
			$positions[] = intval($a->videoFile->qualityDefinition->position);
			$uris[] = array(
				"uri"					=> $a->getUri(),
				"qualityDefinition"		=> $a->videoFile->qualityDefinition
			);
		}
		// reorder so in qualities order
		array_multisort($positions, SORT_NUMERIC, SORT_ASC, $uris);
		return $uris;
	}
	
	public function getDates() {
		return array_merge(parent::getDates(), array('time_recorded', 'scheduled_publish_time'));
	}
	
	// returns true if this video should be accessible now. I.e mediaitem enabled and this enabled etc
	public function getIsAccessible() {
		$sourceFile = $this->sourceFile;
		return $this->enabled && $this->mediaItem->getIsAccessible() && !is_null($sourceFile) && $sourceFile->getFinishedProcessing();
	}
	
	public function scopeAccessible($q) {
		return $q->where("enabled", true)->whereHas("sourceFile", function($q2) {
			$q2->finishedProcessing();
		});
	}
	
}