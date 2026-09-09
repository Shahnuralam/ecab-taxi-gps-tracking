(function ($) {
	'use strict';
	$(function () {
		var section = new URLSearchParams(window.location.search).get('mptbm_section');
		if (!section) return;
		var $tab = $('.mptbm-modern-global-settings [data-tabs-target="#' + section.replace(/[^a-z0-9_-]/gi, '') + '"]');
		if ($tab.length) {
			$tab.first().trigger('click');
			$tab.first()[0].scrollIntoView({block: 'nearest'});
		}
	});
})(jQuery);
