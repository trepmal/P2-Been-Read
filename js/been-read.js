jQuery(document).ready(function($) {

	$('#postlist').on('click', '.toggle-read-status', function(ev) {
		ev.preventDefault();

		var btn = $(this),
			thispost = btn.parents('.post');

		btn.text('...');
		toggle_read_status( thispost );
	});

	function toggle_read_status( post ) { 
		var id = post.attr('id').replace('prologue-', '');
		$.post( been_read.ajaxurl, {
			'action' : 'toggle_read_status',
			'post_id' : id
		}, function(response) {
			post.find('.toggle-read-status').text( response.message );
			bubble = $('#unread-count-bubble');
			bubble.html( parseInt( bubble.text() ) + response.increment );
			if( bubble.text() > 0 ) {
				$('#wp-admin-bar-my-account-default').slideDown();
			}
			if ( response.message == 'Mark Read' ) {
				post.addClass('been_read-new-post');
			}

			if ( response.message == 'Mark Unread' ) {
				post.removeClass('been_read-new-post');
			}

		}, 'json' );
	}

	$('#wp-admin-bar-mark-all-unread-posts div').click( function () {
		var div = $(this);
		div.html('...');
		// live toggle visible posts
		for( var ind in postsOnPage ) {
			post_id = postsOnPage[ind];
			post = $( '#' + post_id );
			if ( post.hasClass('been_read-new-post') ) {
				toggle_read_status( post );
			}
		}
		$.post( been_read.ajaxurl, {
			'action' : 'mark_all_read',
			'skip' : postsOnPage
		}, function(response) {
			div.html(response);
			// on completion, roll up
			$('#wp-admin-bar-my-account-default').slideUp('fast', function() {
				div.html( 'Mark all as read');
			});

		}, 'text' );
	});

});