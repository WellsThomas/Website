<?php namespace uk\co\la1tv\website\models;

class File extends MyEloquent {

	protected $table = 'files';
	protected $fillable = array('in_use');
	
	public function mediaItemWithCover() {
		return $this->belongsTo(self::$p.'MediaItem', 'cover_file_id');
	}
	
	public function mediaItemWithBanner() {
		return $this->belongsTo(self::$p.'MediaItem', 'side_banner_file_id');
	}
	
	public function playlistWithCover() {
		return $this->belongsTo(self::$p.'Playist', 'cover_file_id');
	}
	
	public function playlistWithBanner() {
		return $this->belongsTo(self::$p.'MediaItem', 'side_banner_file_id');
	}
}