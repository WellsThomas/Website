<div class="panel panel-default">
	<div class="panel-heading">
		<h3 class="panel-title">Edit Media</h3>
	</div>
	<div class="panel-body">
		<div class="checkbox">
			<label>
				<input type="checkbox" name="enabled"> Enabled
			</label>
		</div>
		<div class="form-group">
			<label class="control-label">Name</label>
			<input type="text" data-virtualform="1" class="form-control" name="name">
		</div>
		<div class="form-group">
			<label class="control-label">Description (Optional)</label>
			<input type="text" data-virtualform="1" class="form-control" name="description">
		</div>
		<div class="form-group">
			<label class="control-label">Cover Image (Optional)</label>
			<div class="form-control ajax-upload" data-ajaxuploadresultname="cover-image-id" data-ajaxuploadextensions="jpg,jpeg" data-ajaxuploadcurrentfilename="test upload.jpg" data-ajaxuploadcurrentfilesize="1123"></div>
			<input type="hidden" data-virtualform="1" name="cover-image-id" value="2" />
		</div>
		<div class="form-group">
			<label class="control-label">Side Banners Image (Optional)</label>
			<div class="form-control ajax-upload" data-ajaxuploadresultname="side-banners-image-id" data-ajaxuploadextensions="jpg,jpeg" data-ajaxuploadcurrentfilename="" data-ajaxuploadcurrentfilesize=""></div>
			<input type="hidden" data-virtualform="1" name="side-banners-image-id" value="" />
		</div>
		
		<div class="panel-group custom-accordian">
			<div class="panel panel-default">
				<div class="panel-heading">
					<h4 class="panel-title">Video On Demand</h4>
				</div>
				<div class="panel-collapse collapse">
					<div class="panel-body">
						stuff
					</div>
				</div>
			</div>
			<div class="panel panel-default">
				<div class="panel-heading">
					<h4 class="panel-title">Video On Demand</h4>
				</div>
				<div class="panel-collapse collapse">
					<div class="panel-body">
						stuff
					</div>
				</div>
			</div>
		</div>
		
	</div>
	<div class="panel-footer clearfix">
		<div class="pull-left">
			<button type="button" data-virtualform="1" data-virtualformsubmit="1" data-virtualformsubmitmethod="post" data-virtualformsubmitaction="" class="btn btn-primary">Create Media</button>
		</div>
		<div class="pull-right">
			<button type="button" class="btn btn-default">Cancel</button>
		</div>
	</div>
</div>