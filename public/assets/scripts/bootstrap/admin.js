require.config({
	baseUrl: "/assets/scripts",
	paths: {
		jquery: "lib/jquery",
		"jquery.ui.widget": "lib/jquery.ui.widget", // jquery.fileupload expects this path
		bootstrap: "lib/bootstrap"
	},
	shim: {
		bootstrap: ["jquery"]
	}
});

require([
	"bootstrap",
	"app/confirmation-msg",
	"app/custom-accordian",
	"app/custom-form",
	"app/default-ajax-file-upload",
	"app/default-ajax-select",
	"app/default-button-group",
	"app/delete-button",
	"app/page-protect",
	"app/search",
	"app/pages/admin/live-streams-edit-page",
	"app/pages/admin/media-edit-page",
	"app/pages/admin/playlist-edit-page",
	"app/pages/admin/users-edit-page"
], function() {
	// everything loaded
});