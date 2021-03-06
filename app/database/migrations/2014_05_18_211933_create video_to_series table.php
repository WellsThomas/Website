<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateVideoToSeriesTable extends Migration {

	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		Schema::create('video_to_series', function(Blueprint $table)
		{
			$table->integer("media_item_id")->unsigned();
			$table->integer("playlist_id")->unsigned();
			$table->primary(array("media_item_id", "playlist_id"));
			$table->smallInteger("position")->unsigned();
			$table->timestamps();
			
			$table->index("media_item_id");
			$table->index("playlist_id");
			
			$table->foreign("media_item_id")->references('id')->on('videos')->onUpdate("restrict")->onDelete('cascade');
			$table->foreign("playlist_id")->references('id')->on('series')->onUpdate("restrict")->onDelete('cascade');

		});
	}

	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down()
	{
		Schema::drop('video_to_series');
	}

}
