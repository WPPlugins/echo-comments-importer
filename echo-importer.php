<?php
/*
Plugin Name: Echo Comments Importer
Plugin URI: http://wordpress.org/extend/plugins/echo-comments-importer/
Description: Import comments from an Echo export file.
Author: Automattic
Author URI: http://automattic.com
Version: 0.1
Stable tag: 0.1
License: GPL v2 - http://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Notes:

Echo doesn't link a namespace definition. Arggh.

Despite what it says, the js-kit website returns stale exports, making it difficult to iterate testing. The only solution I've found is to register a new site with a different domain (thank somebody for wildcard cnames).

The importer now stores the jskit_guid as commentmeta and will add a jskit_guid to comments that exist in the DB (for comments created before Echo was implemented and migrated into Echo, or for comments that were added to WP while running the Echo plugin).

*/

if ( !defined( 'WP_LOAD_IMPORTERS' ) )
	return;

// Load Importer API
require_once ABSPATH . 'wp-admin/includes/import.php';

if ( !class_exists( 'WP_Importer' ) ) {
	$class_wp_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';
	if ( file_exists( $class_wp_importer ) )
		require_once $class_wp_importer;
}

/**
 * Echo Importer
 *
 */
if ( class_exists( 'WP_Importer' ) ) {
	class Echo_Import extends WP_Importer {
	
		var $post_ids_processed = array ();
		var $inserted_comments = array ();
		var $found_comment_count;
		var $orphan_comments = array();
	
		var $num_comments = 0;
		var $num_duplicates = 0;
		var $num_uncertain = 0;
	
		var $file;
		var $id;
	
		// Prints the header for the admin gui
		function header() {
			echo '<div class="wrap">';
			screen_icon();
			echo '<h2>' . __( 'Import Echo Comments', 'echo-importer' ) . '</h2>';
		}
	
		// Prints the footer for the admin gui
		function footer() {
			echo '</div>';
		}
	
		// Prints the import welcome page
		function greet() {
			echo '<div class="narrow">';
			echo '<p>' . __( 'Howdy! Upload your Echo export file and we&#8217;ll import the comments.', 'echo-importer' ) . '</p>';
			wp_import_upload_form( "admin.php?import=echo&amp;step=1" );
			echo '</div>';
		}
	
		// Parses a string for the passed tag and returns the value
		function get_tag( $string , $tag , $all = FALSE ) {
			global $wpdb;
			preg_match_all( "|<$tag(.*?)>(.*?)</$tag>|is", $string, $matches );
	
			foreach( (array) $matches[2] as $k => $v ) {
				$v = preg_replace('|^<!\[CDATA\[(.*)\]\]>$|s', '$1', $v );
				$v = $wpdb->escape( trim( $v ) );
			
				if( ! $all )
					return $v;
			
				$return[] = (object) array( 
					'attr' => ( empty( $matches[1][ $k ] ) ? (object) array() : (object) shortcode_parse_atts( $matches[1][ $k ] ) ),
					'val' => $v,
				);
			}
	
			return $return;
		}
	
		// Determines how we will read the file
		function has_gzip() {
			return is_callable('gzopen');
		}
	
		// Open the file
		function fopen($filename, $mode='r') {
			if ( $this->has_gzip() )
				return gzopen($filename, $mode);
			return fopen($filename, $mode);
		}
		
		// Return true if at end of file
		function feof($fp) {
			if ( $this->has_gzip() )
				return gzeof($fp);
			return feof($fp);
		}
	
		// Return single line from file
		function fgets($fp, $len=8192) {
			if ( $this->has_gzip() )
				return gzgets($fp, $len);
			return fgets($fp, $len);
		}
	
		// Close the file
		function fclose($fp) {
			if ( $this->has_gzip() )
				return gzclose($fp);
			return fclose($fp);
		}
	
		// Loops through the files to either count or process comments based on passed argument
		function get_entries( $process_comment_func= NULL ) {
			set_magic_quotes_runtime(0);
	
			$doing_entry = false;
			$is_echo_file = false;
	
			$fp = $this->fopen($this->file, 'r');
			if ($fp) {
				while ( !$this->feof($fp) ) {
					$importline = rtrim($this->fgets($fp));
	
					// this doesn't check that the file is perfectly valid but will at least confirm that it's not the wrong format altogether
					if ( !$is_echo_file && strpos( $importline , 'jskit:attribute' ) )
						$is_echo_file = true;
	
					// Are we starting a new item?
					if ( false !== strpos($importline, '<item>') ) {
						$this->post = '';
						$doing_entry = true;
						continue;
					}
					
					// Are we closing the current item
					if ( false !== strpos($importline, '</item>') ) {
						$doing_entry = false;
						// Count or process the comment
						if ( $process_comment_func )
							call_user_func( $process_comment_func, $this->post );
						continue;
					}
					
					// If we're in the middle of a post
					if ( $doing_entry ) {
						$this->post .= $importline . "\n";
					}
				}
	
				$this->fclose($fp);
			}
	
			return $is_echo_file;
	
		}
	
		// Determine if the file uploaded appears to be an Echo file and act accordingly
		function check_upload() {
			$is_echo_file = $this->get_entries( array( &$this, 'count_entries' ));
	
			if ( $is_echo_file ) {
				$this->options();
			}
			else {
				echo '<h2>'.__('Invalid file', 'echo-importer').'</h2>';
				echo '<p>'.__('Please upload a valid Echo export file.', 'echo-importer').'</p>';
			}
		}
	
		// Display the options page
		function options() {
			?>
			<h2><?php _e('Import Options', 'echo-importer'); ?></h2>
			<p><?php printf( _n( 'It looks like there&#8217;s %s comment in the file.' , 'It looks like there are %s comments in the file.', $this->found_comment_count, 'echo-importer' ), $this->found_comment_count ); ?></p>
			<p><?php _e('Click Next to import all of them.', 'echo-importer'); ?></p>
	
			<form action="?import=echo&amp;step=2&amp;id=<?php echo $this->id; ?>" method="post">
			<?php wp_nonce_field('import-echo'); ?>
			<p class="submit">
			<input type="submit" class="button" value="<?php echo esc_attr__('Next', 'echo-importer'); ?>" /><br />
			</p>
			</form>
			<?php
		}
	
		// Increment the entries count
		function count_entries( $comment ) {
			$entry = $this->get_tag( $comment, 'guid' );
	
			if( $entry )
				$this->found_comment_count++;
		}
	
		// Loop through comments and report error or process
		function process_comments() {
			echo '<ol>';
	
			$this->get_entries( array( &$this, 'process_comment' ));
			$this->process_orphan_comments(); // call it once to capture replies on the last post
			$this->process_orphan_comments( TRUE ); // call it again to force import any remaining unmatched orphans
	
			echo '</ol>';
	
			wp_import_cleanup($this->id);
			do_action('import_done', 'echo');
	
			if( $this->num_comments )
				echo '<h3>'.sprintf( _n( 'Imported %s comment.' , 'Imported %s comments.', $this->num_comments, 'echo-importer' ) , $this->num_comments ) .'</h3>';
	
			if( $this->num_duplicates )
				echo '<h3>'.sprintf( _n( 'Skipped %s duplicate.' , 'Skipped %s duplicates.', $this->num_duplicates, 'echo-importer' ) , $this->num_duplicates ) .'</h3>';
	
			if( $this->num_uncertain )
				echo '<h3>'.sprintf( _n( 'Could not determine the correct item to attach %s comment to.' , 'Could not determine the correct item to attach %s comments to.', $this->num_uncertain, 'echo-importer' ) , $this->num_uncertain ) .'</h3>';
	
			echo '<h3>'.sprintf( __( 'All done.', 'echo-importer' ).' <a href="%s">'.__( 'Have fun!', 'echo-importer').'</a>', get_option('home')).'</h3>';
	
		}
	
		// Process a specific comment
		function process_comment( $comment ) {
			global $wpdb;
	
			set_time_limit( 60 );
	
			$new_comment['jskit_guid']				= $this->get_tag( $comment, 'guid' );
			$new_comment['jskit_parent_guid']		= $this->get_tag( $comment, 'jskit:parent-guid' );
			$new_comment['comment_author']			= $this->get_tag( $comment, 'author' );
			$gmt_unixtime 							= strtotime( $this->get_tag( $comment, 'pubDate' ));
			$new_comment['comment_date_gmt']		= date( 'Y-m-d H:i:s' , $gmt_unixtime );
			$new_comment['comment_date']			= date( 'Y-m-d H:i:s' , $gmt_unixtime + get_option( 'gmt_offset' ) * 3600 ); // strangely, get_date_from_gmt returns unexpected results here
			$new_comment['comment_content']			= $this->get_tag( $comment, 'description' );
			$new_comment['comment_approved']		= 1; // the export appears to exclude non-public comments
			$new_comment['comment_type']			= ''; // echo doesn't appear to support trackbacks or pingbacks
	
			$jskit_attrs = $this->get_tag( $comment, 'jskit:attribute' , TRUE );
			foreach( $jskit_attrs as $temp ) {
				switch( $temp->attr->key ) {
					case 'permalink':
						$post_url = $temp->attr->value;
						break;
					case 'IP':
						$new_comment['comment_author_IP'] = $temp->attr->value;
						break;
					case 'foreign':
						// WP-native comments imported into Echo/JS-Kit get double-encoded entities here
						// the bug is consistent throughout the JS-Kit world, and persists in the exports
						if( 2 == $temp->attr->value )
							$new_comment['comment_author'] = html_entity_decode( $new_comment['comment_author'] );
						break;
					case 'NativeCmtId':
						$new_comment['comment_id'] = $temp->attr->value;
						break;
				}
			}
	

			$new_comment['comment_post_ID']		= ( trailingslashit( $post_url ) != trailingslashit( get_option( 'siteurl' ) ) ) ? (int) url_to_postid( $post_url ) : (int) get_option( 'page_on_front' );
			if( ! $new_comment['comment_post_ID'] ) { // Couldn't determine the post id from path
				echo '<li>'. sprintf( __( 'Couldn&#8217;t determine the correct item to attach this comment to. Given URL: <code>%s</code>.', 'echo-importer' ) , esc_url( $post_url )) ."</li>\n";
				$this->num_uncertain++;
				return 0;
			}
	
			$new_comment = array_map( 'html_entity_decode' , $new_comment );
	
			if ( empty( $new_comment['jskit_parent_guid'] ) || $this->comment_exists( array( 'jskit_guid' => $new_comment['jskit_parent_guid'] ) ) ) {
				$new_comment['comment_parent'] 	= $this->inserted_comments[ $new_comment['jskit_parent_guid'] ];
				$this->insert_comment( $new_comment );
			} else {
				$this->orphan_comments[ $new_comment['jskit_guid'] ] = $new_comment;
			}
	
			// try to process orphan comments if we've moved on to a new post ID
			if ( count( $this->orphan_comments ) && $new_comment['comment_post_ID'] <> $last_post_id )
				$this->process_orphan_comments();
			$last_post_id = $new_comment['comment_post_ID'];
	
		}
	
		// Attempt to match previously unpaired comments with their parents
		function process_orphan_comments( $force = FALSE ) {
			if( $force ) {
				if ( count( $this->orphan_comments ) )
					echo '<li>' . sprintf( _n( 'Processing %s orphan.' , 'Processing %s orphans.', count( $this->orphan_comments ), 'echo-importer' ) , count( $this->orphan_comments ) ) . '.</li>';
				else
					return;
			}
	
			ksort( 	$this->orphan_comments );
			while( $this->orphan_comments ) {
				foreach( $this->orphan_comments as $comment ){
					if( $this->comment_exists( array( 'jskit_guid' => $comment['jskit_parent_guid'] ) ) || $force ) {
						$comment['comment_parent'] = $this->inserted_comments[ $comment['jskit_parent_guid'] ];
						$this->insert_comment( $comment );
						unset( $this->orphan_comments[ $comment['jskit_guid'] ] );
					}
				}
	
				// detect a loop condition when a comment's parent can't be found
				if( count( $this->orphan_comments ) == $last_count )
					return;
	
				$last_count = count( $this->orphan_comments );
			}
		}
	
		// Insert comment into database
		function insert_comment( $comment ) {
			if ( ! $this->comment_exists( $comment ) ) {
				unset( $comment['comment_id'] );
	
				$comment = wp_filter_comment( $comment );
				$this->inserted_comments[ $comment['jskit_guid'] ] = wp_insert_comment( $comment );
	
				update_comment_meta( $this->inserted_comments[ $comment['jskit_guid'] ], 'jskit_guid' , $comment['jskit_guid'], TRUE );
	
				$this->post_ids_processed[ $comment['comment_post_ID'] ]++;
				$this->num_comments++;
	
				echo '<li>'. sprintf( __( 'Imported comment by %s on %s.', 'echo-importer') , esc_html( stripslashes( $comment['comment_author'] ) ) , get_the_title( $comment['comment_post_ID'] ) ) . "</li>\n";
			} else {
				$this->num_duplicates++;
				echo '<li>' . __( 'Skipped duplicate comment.', 'echo-importer' ) . "</li>\n";
			}
		}
	
		// Does this comment already exist in the database?
		function comment_exists( $comment ) {
			global $wpdb;
	
			// must have jskit_guid
			if( !isset( $comment['jskit_guid'] ) )
				return FALSE;
	
			// edge case: we've been given a comment_id because the comment was received through the Echo WP plugin
			// still, the comment_id may not be correct, so confirm the time is close (because the servers' clocks may not be sync'd)
			if ( isset( $comment['comment_id'] ) ) {
				if( $local_comment_date_gmt = $wpdb->get_var( $wpdb->prepare( "SELECT comment_date_gmt FROM $wpdb->comments 
					WHERE comment_id = %s", (int) $comment['comment_id'] ) ) 
					&& 2880 < absint( strtotime( $comment['comment_date_gmt'] ) - strtotime( $local_comment_date_gmt ) ) ) {
						update_comment_meta( $comment_id, 'jskit_guid' , $comment['jskit_guid'], TRUE );
						$this->inserted_comments[ $comment['jskit_guid'] ] = $comment['comment_id'];
						return $comment['comment_id'];
				}
			}
	
			// look for jskit_guids in the processed comment list (meaning comment parent has just been imported)
			if( isset( $this->inserted_comments[ $comment['jskit_guid'] ] ) )
				return $this->inserted_comments[ $comment['jskit_guid'] ];
	
			// look for jskit_guids in the commentmeta (meaning the comment parent was previously imported)
			if( ( $comment_id = $wpdb->get_var( $wpdb->prepare( "SELECT comment_id FROM $wpdb->commentmeta WHERE meta_key = 'jskit_guid' AND meta_value = %s", $comment['jskit_guid'] ) ) ) && $comment_id > 0 ) {
				$this->inserted_comments[ $comment['jskit_guid'] ] = $comment_id;
				return $comment_id;
			}
	
			// finally, try to match the comment using WP's comment_exists() rules
			// unfortunately, we need the comment_id, not comment_post_ID, so we can't use the built-in
			// add a jskit_guid to the comment if it matches
			if( isset( $comment['comment_author'] , $comment['comment_date'] ) ) {
				$comment_author = stripslashes( $comment['comment_author'] );
				$comment_date = stripslashes( $comment['comment_date'] );
				$comment_id = $wpdb->get_var( $wpdb->prepare( "SELECT comment_id FROM $wpdb->comments WHERE comment_author = %s AND comment_date = %s", $comment_author, $comment_date ) );
	
				update_comment_meta( $comment_id, 'jskit_guid' , $comment['jskit_guid'], TRUE );
				$this->inserted_comments[ $comment['jskit_guid'] ] = $comment_id;
				return $comment_id;
			}
	
			return FALSE;
		}
	
		// Runs before import
		function import_start() {
			wp_defer_comment_counting( true );
			do_action( 'import_start' );
		}
	
		// Runs after import
		function import_end() {
			do_action( 'import_end' );
	
			// clear the caches after backfilling
			foreach ( $this->post_ids_processed as $post_id )
				clean_post_cache( $post_id );
	
			wp_defer_comment_counting( false );
		}
	
		// Kicks off the import process
		function import( $id ) {
			$this->id = (int) $id;
			$file = get_attached_file( $this->id );
			$this->import_file( $file );
		}
	
		// Drives the import process
		function import_file( $file ) {
			$this->file = $file;
	
			$this->import_start();
			wp_suspend_cache_invalidation(true);
			$this->get_entries();
			$result = $this->process_comments();
			wp_suspend_cache_invalidation(false);
			$this->import_end();
	
			if ( is_wp_error( $result ) )
				return $result;
		}
	
		// handles the file upload
		function handle_upload() {
			$file = wp_import_handle_upload();
			if ( isset($file['error']) ) {
				echo '<p>' . __( 'Sorry, there has been an error.', 'echo-importer' ) . '</p>';
				echo '<p><strong>' . $file['error'] . '</strong></p>';
				return false;
			}
			$this->file = $file['file'];
			$this->id = (int) $file['id'];
			return true;
		}
	
		// Which page (step in the admin GUI) are we showing
		function dispatch() {
			if ( empty ($_GET['step'] ) )
				$step = 0;
			else
				$step = (int) $_GET['step'];
	
			$this->header();
			switch ( $step ) {
				case 0 :
					$this->greet();
					break;
				case 1 :
					check_admin_referer( 'import-upload' );
					if ( $this->handle_upload() )
						$this->check_upload();
					break;
				case 2:
					check_admin_referer( 'import-echo' );
					$result = $this->import( $_GET['id'] );
					if ( is_wp_error( $result ) )
						echo $result->get_error_message();
					break;
			}
			$this->footer();
		}
	
		// Constructor - does nothing
		function Echo_Import() {
	
		}
	}

	/**
	 * Register Echo Importer
	 *
	 */
	$echo_import = new Echo_Import();
	
	register_importer( 'echo', 'Echo Comments', __( 'Import comments from an Echo export file.', 'echo-importer' ), array ( $echo_import, 'dispatch' ) );

} // class_exists( 'WP_Importer' )

function echo_importer_init() {
    load_plugin_textdomain( 'echo-importer', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'init', 'echo_importer_init' );
