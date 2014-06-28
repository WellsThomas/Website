<?php namespace uk\co\la1tv\website\models;

class LiveStreamQuality extends MyEloquent {
	
	protected $table = 'live_streams_qualities';
	protected $fillable = array('quality_id', 'position');

	public function liveStream() {
		return $this->belongsTo(self::$p.'LiveStream', 'live_stream_id');
	}
	
	public function qualityDefinition() {
		return $this->belongsTo(self::$p.'LiveStreamQuality', 'quality_definition_id');
	}
}	