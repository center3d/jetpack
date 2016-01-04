<?php
/**
 * Module Name: VideoPress
 * Module Description: Upload and embed videos right on your site. (Subscription required.)
 * First Introduced: 2.5
 * Free: false
 * Requires Connection: Yes
 * Sort Order: 27
 * Module Tags: Photos and Videos
 * Additional Search Queries: video, videos, videopress
 */

include_once( dirname( __FILE__ ) . '/videopress-v2/videopress.php' );

if ( is_admin() ) {
	include_once( dirname( __FILE__ ) . '/videopress-v2/admin.php' );
}
