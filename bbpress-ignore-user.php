<?php
/**
 * Plugin Name: bbPress Ignore User
 * Plugin URI: http://wordpress.org/extend/plugins/bbpress-ignore-user/
 * Description: Allow members of the forum to selectively have a user's posts and topics hidden from view.
 * Dependencies: bbpress/bbpress.php
 * Version: 0.2
 * Author: Jason Schwarzenberger
 * Author URI: http://master5o1.com/
 */
/*  Copyright 2011  Jason Schwarzenberger  (email : jason@master5o1.com)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

if ( !isset($_GET['show_ignored_users']) ) {
	add_filter( 'bbp_has_topics', array('bbp_5o1_ignore_user', 'hide_ignored_topics'), 10, 2 );
	add_filter( 'bbp_has_replies', array('bbp_5o1_ignore_user', 'hide_ignored_replies'), 10, 2 );
}

add_action( 'wp_enqueue_scripts', array('bbp_5o1_ignore_user', 'jquery_script') );
add_action( 'wp_ajax_bbp_5o1_unignore_user', array('bbp_5o1_ignore_user', 'ajax_unignore_user') );
add_action( 'wp_ajax_bbp_5o1_ignore_user', array('bbp_5o1_ignore_user', 'ajax_ignore_user') );
add_filter( 'bbp_get_reply_author_link', array('bbp_5o1_ignore_user', 'show_ignore_user_button'), 10, 2 );
add_action( 'bbp_template_notices', array('bbp_5o1_ignore_user', 'show_users_ignore_list') );

// Plugin class:
class bbp_5o1_ignore_user {

	function jquery_script() {
		wp_enqueue_script( 'jquery' );
		wp_localize_script( 'jquery', 'IgnoreUser', array( 'ajaxurl' => site_url().'/wp-admin/admin-ajax.php' ) );
	}

	function get_ignored_users($user_id = 0) {
		if ( empty($user_id) )
			$user_id = bbp_get_current_user_id();
		$raw = get_user_meta($user_id, 'bbp_5o1_ignored_users', true);
		if ( empty($raw) )
			return false;
		$raw = explode(',',$raw);
		$ignored_users = array();
		foreach ($raw as $id) {
			$user = get_userdata( $id );
			if ( !user_can($user->ID, 'moderate') ) // prevent moderators and administrators from being ignored.
				$ignored_users[$user->ID] = $user->user_login;
		}
		return $ignored_users;
	}

	function set_ignored_users($user_id, $ignored_users) {
		$before = bbp_5o1_ignore_user::get_ignored_users($user_id);
		if ( !empty($before) )
			delete_user_meta( $user_id, 'bbp_5o1_ignored_users' );
		if ( empty($ignored_users) )
			return;
		$ignored_ids = "";
		foreach (array_keys($ignored_users) as $id) {
			if ( !user_can($id, 'moderate') )
				$ignored_ids .= "," . $id;
		}
		$ignored_ids = substr($ignored_ids, 1);
		if ( !empty($ignored_ids) )
			update_user_meta( $user_id, 'bbp_5o1_ignored_users', $ignored_ids );
	}

	function hide_ignored_topics($have_posts, $topic_query) {
		$bbp = bbpress();
		$ignored_users = bbp_5o1_ignore_user::get_ignored_users();
		if ( empty($ignored_users) ) // Nothing to do.
			return $have_posts;
		if ( in_array( bbp_get_displayed_user_id(), array_keys($ignored_users) ) ) // Don't hide a user's topics if they're on their profile archive.
			return $have_posts;

		$posts = $bbp->topic_query->posts;
		$saved_posts = null;
		$i = 0;
		foreach ( $posts as $post ) {
			if ( in_array($post->post_author, array_keys($ignored_users) ) ) {
				$i++;
				if ( user_can($post->post_author, 'moderate') ) {
					$i--;
					$saved_posts[] = $post;
				}
				continue;
			}
			$saved_posts[] = $post;
		}

		$bbp->topic_query->post_count -= $i;
		$bbp->topic_query->posts = $saved_posts;

		if ($i != 0) {
			$code = "print '<div class=\"bbp-template-notice\"><p>Hiding ".$i." topic".(($i!=1)?"s":"")." from users that you have chosen to ignore. <a href=\"?show_ignored_users\">Show all topics</a>.</p></div>';";
			add_action( 'bbp_template_before_topics_loop' , create_function('', $code) );
		}

		return $have_posts;
	}

	function hide_ignored_replies($have_posts, $reply_query) {
		$bbp = bbpress();
		$ignored_users = bbp_5o1_ignore_user::get_ignored_users();
		if ( empty($ignored_users) ) // Nothing to do.
			return $have_posts;
		if ( in_array( bbp_get_displayed_user_id(), array_keys($ignored_users) ) ) // Don't hide a user's replies if they're on their profile archive.
			return $have_posts;

		$posts = $bbp->reply_query->posts;
		$saved_posts = null;
		$i = 0;
		foreach ( $posts as $post ) {
			if ( in_array($post->post_author, array_keys($ignored_users) ) ) {
				$i++;
				if ( user_can($post->post_author, 'moderate') ) {
					$i--;
					$saved_posts[] = $post;
				}
				continue;
			}
			$saved_posts[] = $post;
		}

		$bbp->reply_query->post_count -= $i;
		$bbp->reply_query->posts = $saved_posts;

		if ($i != 0) {
			$code = "print '<div class=\"bbp-template-notice\"><p>Hiding ".$i." repl".(($i!=1)?"ies":"y")." from users that you have chosen to ignore. <a href=\"?show_ignored_users\">Show all posts</a>.</p></div>';";
			add_action( 'bbp_template_before_replies_loop' , create_function('', $code) );
		}

		return $have_posts;
	}

	function show_users_ignore_list() {
		// User isn't supposed to see this list here, so don't try showing it.
		if ( !( bbp_is_user_home() || current_user_can( 'edit_users' ) ) )
			return;
		// We only want to show this on either the current_user's or displayed_user's profiles.
		$displayed_user_id = bbp_get_displayed_user_id();
		if ( !in_array( substr(site_url(),0,strlen(site_url())-strlen(parse_url(site_url(),PHP_URL_PATH))) . $_SERVER['REQUEST_URI'] ,array ( bbp_get_user_profile_url($displayed_user_id), bbp_get_user_profile_url(bbp_get_current_user_id())) ) )
			return;
		$ignored_users = bbp_5o1_ignore_user::get_ignored_users($displayed_user_id);
		// User doesn't have any usernames in the list, so don't show it.
		if ( empty($ignored_users) )
			return;
		?><table class="profile-fields zebra">
			<tr>
				<td class="label">Ignored Users List</td>
				<td class="data"><?
				foreach ($ignored_users as $user_id => $user_login) :
				$ajax = "jQuery.post(IgnoreUser.ajaxurl,{'action':'bbp_5o1_unignore_user','data':'delete ".$user_id.";user_id ".$displayed_user_id."'},function(response){if (response){user=document.getElementById('ignored-user-".$user_id."');user.parentNode.removeChild(user);}});";
				?>
					<span id="ignored-user-<?php print $user_id; ?>" style="display: inline-block; line-height: 1.5em; margin: 3px; padding: 2px 5px; background: #eee;">
						<span style="padding: 0 5px 0 0;"><?php print $user_login; ?></span>
						<a style="cursor: pointer;" title="Remove" onclick="<?php print $ajax; ?>;">&#x2716;</a>
					</span>
				<?php endforeach; ?></td>
			</tr>
		</table><?php
	}

	function show_ignore_user_button($author_link) {
		$user_id = bbp_get_current_user_id();
		$ignored_users = bbp_5o1_ignore_user::get_ignored_users($user_id);
		$author_id = bbp_get_reply_author_id();
		$reply_id = bbp_get_reply_id();
		// So if it's not a reply we're adding it to,
		// or if the reply author is the current user,
		// or if the reply author can moderate the forum,
		// then we don't show the link.
		if ( !is_user_logged_in() || empty($reply_id) || $user_id == $author_id || user_can($author_id, 'moderate') || ( !bbp_is_single_topic() && !bbp_is_single_reply() ) )
			return $author_link;
		$elm_id = "ignore-user-".$author_id."-".$reply_id;
		$ajax = "jQuery.post(IgnoreUser.ajaxurl,{'action':'bbp_5o1_ignore_user','data':'ignore ".$author_id.";user_id ".$user_id."'},function(response){if (response=='true'){elm=document.getElementById('".$elm_id."');elm.parentNode.removeChild(elm);}});";
		$label = "Ignore User";
		if ( !empty($ignored_users) && in_array($author_id, array_keys($ignored_users)) ) {
			$ajax = "jQuery.post(IgnoreUser.ajaxurl,{'action':'bbp_5o1_unignore_user','data':'delete ".$author_id.";user_id ".$user_id."'},function(response){if (response=='true'){elm=document.getElementById('".$elm_id."');elm.parentNode.removeChild(elm);}});";
			$label = "Unignore User";
		}
		$link = '<div class="bbpiu-ignore-link"><a style="cursor: pointer;" id="'.$elm_id.'" onclick="'.$ajax.'">'.$label.'</a></div>';
		return $author_link . '<br />' . $link;
	}

	// The following two functions are probably a mess that should be cleaned up later.
	function ajax_unignore_user() {
		// data is a string like: 'delete 1;user_id 2'
		// meaning delete the ignored user with id=1 from user=2's ignore list.
		$data = $_POST['data'];
		$parts = explode(";", $data);
		foreach ($parts as $part) {
			$piece = explode(" ", $part);
			$command[$piece[0]] = $piece[1];
		}
		if ( !isset($command['user_id']) )
			die('false');
		// Here I should actually test to make sure that the two user ids are actually valid
		// and that they exist in the database.  But I haven't done that yet.
		$ignored_users = bbp_5o1_ignore_user::get_ignored_users($command['user_id']);
		if ( isset($command['delete']) ) {
			if ( empty($ignored_users) )
				die('false');
			$response = 'false';
			if ( !in_array($command['delete'], array_keys($ignored_users) ) )
				die('false');
			if ( in_array($command['delete'], array_keys($ignored_users) ) )
				$response = 'false';
			$ignored_list = array();
			foreach ($ignored_users as $user_id => $user_login) {
				if ($command['delete'] == $user_id)
					continue;
				$ignored_list[$user_id] = $user_login;
			}
			$ignored_users = $ignored_list;
			if ( empty($ignored_users) || !in_array($command['delete'], array_keys($ignored_users) ) ) {
				$response = 'true';
				bbp_5o1_ignore_user::set_ignored_users($command['user_id'], $ignored_users);
			}
		}
		die($response);
	}

	function ajax_ignore_user() {
		// data is a string like: 'ignore 1;user_id 2'
		// meaning add the user with id=1 to user=2's ignore list.
		$data = $_POST['data'];
		$parts = explode(";", $data);
		foreach ($parts as $part) {
			$piece = explode(" ", $part);
			$command[$piece[0]] = $piece[1];
		}
		if ( !isset($command['user_id']) )
			die('false');
		if ( $command['user_id'] == $command['ignore'] )
			die('false');
		$ignored_users = bbp_5o1_ignore_user::get_ignored_users($command['user_id']);
		if ( isset($command['ignore']) ) {
			if ( user_can($command['ignore'], 'moderate') )
				die('false');
			$response = 'false';
			if ( !empty($ignored_users) ) {
				if (in_array($command['ignore'], array_keys($ignored_users) ) )
					die('false');
			}
			if ( empty($ignored_users) || !in_array($command['ignore'], array_keys($ignored_users) ) ) {
				$user = get_userdata($command['ignore']);
				if ( !empty($user))
					$ignored_users[$user->ID] = $user->user_login;
			}
			if ( in_array($command['ignore'], array_keys($ignored_users)) )  {
				$response = 'true';
				bbp_5o1_ignore_user::set_ignored_users($command['user_id'], $ignored_users);
			}
		}
		die($response);
	}

}
 ?>