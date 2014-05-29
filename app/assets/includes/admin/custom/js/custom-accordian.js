$(document).ready(function() {

	$('.custom-accordian').each(function() {
		
		var $parent = $(this).first();
		
		$(this).find(".panel-collapse").each(function() {
			$(this).collapse({
				parent: $parent,
				toggle: false
			});
		});
		
		$(this).find('.panel-heading').each(function() {
			
			$(this).css("cursor", "pointer");
			
			var $title = $(this).find(".panel-title");
			var origStyle = $title.css("text-decoration");
			$(this).hover(function() {
				$title.css("text-decoration", "underline");
			}, function() {
				$title.css("text-decoration", origStyle);
			});
			
			$(this).click(function() {
				$(this).closest(".panel").children(".panel-collapse").first().collapse("toggle");
			});
		});
	});
	
});