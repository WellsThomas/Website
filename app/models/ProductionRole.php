<?php namespace uk\co\la1tv\website\models;

class ProductionRole extends MyEloquent {

	protected $table = 'production_roles';
	protected $fillable = array('id', 'name', 'description', 'position');
	
	public function credits() {
		return $this->hasMany(self::$p.'Credit', 'production_role_id');
	}
	
	public function productionRolePlaylist() {
		return $this->hasOne(self::$p.'ProductionRolePlaylist', 'production_role_id');
	}
	
	public function productionRoleMediaItem() {
		return $this->hasOne(self::$p.'ProductionRoleMediaItem', 'production_role_id');
	}

}