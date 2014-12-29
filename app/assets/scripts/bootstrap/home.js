require.config({
	baseUrl: "/assets/scripts",
	paths: {
		jquery: "lib/jquery",
		ga: "https://www.google-analytics.com/analytics"
	},
	shim: {
		"lib/bootstrap": ["jquery"],
		"ga": {
			exports: "ga"
		}
	}
});

(function() {
	var startTime = new Date().getTime();

	require([
		"app/logger",
		"app/google-analytics",
		"app/error-handler",
		"lib/bootstrap",
		"app/custom-accordian",
		"app/default-button-group",
		"app/fit-text-handler",
		"app/synchronised-time",
		"app/video-js",
		"app/jslink",
		"app/confirmation-msg",
		"app/pages/home/player-page",
		"app/pages/home/account-page",
		"app/pages/home/playlist",
		"app/pages/home/promo-loader",
		"app/pages/home/home-page",
	], function(logger, googleAnalytics) {
		// everything loaded
		logger.info("Page loaded.");
		googleAnalytics.registerModulesLoadTime("Home", new Date().getTime() - startTime);
	});
	
})();