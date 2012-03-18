// hiding stuff

var hideDynamicThird = function() {
	$('#dynamic_third').addClass('hide');
	$('#top_menu .contacts').removeClass('hide');
	$('#top_menu .user_profile').removeClass('hide');
	
}

var showDynamicThird = function() {
	$('#dynamic_third').removeClass('hide');
	$('#top_menu .contacts').addClass('hide');
	$('#top_menu .user_profile').addClass('hide');
}


// sets the height for everything beside the dynamic widgets area
var resizeHeight = function(){
	var page = $('#page');
	var body = $('body');

	// set page height to body height
	page.height(body.height());

	// header
	var header_height = 50
	$('#header').height(header_height);

	// top menu
	$('#top_menu li').height("20px");

	//widgets
	var widgets_height = page.height() - header_height - 37;
	$('#widgets').height(widgets_height);

	// topic list
	$('#topics_list').height(widgets_height + 17);
	$('#topics_list .tab_content').height(widgets_height - 1);
};

// sets the design for a narrow window
var resizeNarrow = function(){
	hideDynamicThird();
	
	var page_width = $('body').width();

	// topic view
	var topic_view_width = 500;
	$('#topic_view').width(topic_view_width);

	var topic_view_users_width = 40;
	$('#topic_view .users').width(topic_view_users_width);

	$('#topic_view .topic').width(topic_view_width -topic_view_users_width);

	// topics list
	$('#topics_list').width(page_width - topic_view_width - 40);
};

// sets the design for a wider
var resizeWide = function() {
	var body = $('body');

	showDynamicThird();
};


// set callbacks
$(document).ready(function() {
	$(window).resize();
});

$(window).resize(function () {
	resizeHeight();

	var body = $('body');
	// dynamic third
	if (body.width() < 900) {
		resizeNarrow();
	} else {
		resizeWide();
	};

});