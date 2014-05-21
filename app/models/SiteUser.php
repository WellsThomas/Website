<?php namespace uk\co\la1tv\website\models;

class SiteUser extends MyEloquent {

	protected $table = 'site_users';
	protected $fillable = array('fb_uid', 'first_name', 'last_name', 'name', 'email');
	
	public function comments() {
		return $this->hasMany(self::$p.'MediaItemComment', 'site_user_id');
	}

	public function likes() {
		return $this->hasMany(self::$p.'MediaItemLike', 'site_user_id');
	}

}