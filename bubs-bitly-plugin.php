<?php
/*
Plugin Name: Bubs' Bit.ly Plugin
Plugin URI: http://bubblessoc.net
Description: Automatically shorten post permalinks using the Bit.ly API
Version: 1.0
Author: Bubs
Author URI: http://bubblessoc.net
*/

// Needs to allow for custom post types if released publicly!
define('BBP_PLUGIN_SLUG', "bubs-bitly-plugin");

class BubsBitlyPlugin {
  private $_settings;
  
  function __construct() {
    $this->_settings = get_option('bbp_settings', array('bitly_api_key' => '', 'bitly_username' => '', 'bitly_domain' => 'bit.ly'));
    
  	// Add Settings/Options Page
  	add_action('admin_init', array($this, 'initSettings'));
  	add_action('admin_menu', array($this, 'adminMenu'));
  	add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'actionLinks'));
  	
  	// Add Post Meta Box
  	add_action('add_meta_boxes', array($this, 'addMetaBox'));
  	
  	// Add Meta Box JS
    add_action('admin_enqueue_scripts', array($this, 'addMetaBoxJS'));
  	
  	// Shorten Permalink on Publish
  	add_action('transition_post_status', array($this, 'autoShortlink'), 10, 3);
  	
  	// Manually Generate Permalink for a Published Post
  	add_action('wp_ajax_bbp_generate_shortlink', array($this, 'manualShortlink'));
  	
  	// Short-circuit wp_get_shortlink()
  	add_filter('get_shortlink', array($this, 'getShortlink'), 10, 4);
  }
  
  function adminMenu() {
    add_options_page("Bubs' Bit.ly Plugin", "Bubs' Bit.ly Plugin", 'manage_options', BBP_PLUGIN_SLUG, array($this, 'optionsPage'));
  }
  
  function actionLinks( $actions ) {
    $actions[] = '<a href="options-general.php?page='. BBP_PLUGIN_SLUG .'">Settings</a>';
    return $actions;
  }
  
  function optionsPage() {
    if (!current_user_can('manage_options'))  {
  		wp_die( __('You do not have sufficient permissions to access this page.') );
  	}
?>
<div class="wrap">
  <div id="icon-options-general" class="icon32"><br></div>
	<h2>Bubs' Bit.ly Plugin Settings</h2>
	<form action="options.php" method="post">
	  <?php settings_fields('bbp_option_group'); ?>
    <?php do_settings_sections( BBP_PLUGIN_SLUG ); ?>
    <p class="submit">
      <input name="submit" type="submit" id="submit" class="button-primary" value="<?php esc_attr_e('Save Changes'); ?>" />
    </p>
	</form>
</div>
<?php
  }
  
  function initSettings() {
    register_setting('bbp_option_group', 'bbp_settings', array($this, 'validateOptions'));
    add_settings_section('bbp_settings_section', '', array($this, 'sectionContent'), BBP_PLUGIN_SLUG);
    add_settings_field('bitly_api_key', 'Bit.ly API Key', array($this, 'settingsField'), BBP_PLUGIN_SLUG, 'bbp_settings_section', array('id' => 'bitly_api_key'));
    add_settings_field('bitly_username', 'Bit.ly Username', array($this, 'settingsField'), BBP_PLUGIN_SLUG, 'bbp_settings_section', array('id' => 'bitly_username'));
    add_settings_field('bitly_domain', 'Bit.ly Domain', array($this, 'domainSettingsField'), BBP_PLUGIN_SLUG, 'bbp_settings_section');
  }
  
  function validateOptions( $values ) {
    $bitly_domain = preg_replace('/^http:\/\//i', '', trim($values['bitly_domain']));
    $bitly_domain = preg_replace('/\/$/', '', $bitly_domain);
    return array(
      'bitly_api_key' => trim($values['bitly_api_key']),
      'bitly_username' => trim($values['bitly_username']),
      'bitly_domain' => $bitly_domain
    );
  }
  
  function sectionContent() {}
    
  function settingsField( $args ) {
    echo '<input name="bbp_settings[' . $args['id'] . ']" type="text" id="' . $args['id'] . '" value="' . $this->_settings[ $args['id'] ] . '" class="regular-text" />';
  }
  
  function domainSettingsField() {
    if ( isset($this->_settings['bitly_domain']) && !empty($this->_settings['bitly_domain']) )
      $domain = $this->_settings['bitly_domain'];
    else
      $domain = "bit.ly";
    
    echo '<select id="bitly_domain" name="bbp_settings[bitly_domain]">';
    echo '<option'. ($domain == "j.mp" ? ' selected' : '') .'>j.mp</option>';
    echo '<option'. ($domain == "bit.ly" ? ' selected' : '') .'>bit.ly</option>';
    if ( $domain != "j.mp" && $domain != "bit.ly" )
      echo '<option selected>'. $domain . '</option>';
    echo '<option id="bitly_custom_domain">Custom</option>';
    echo '</select>';
  }
  
  function addMetaBox() {
    add_meta_box('bitlydiv', 'Bit.ly Shortlink', array($this, 'metaBoxContent'), 'post', 'side', 'high', array());
  }
  
  function metaBoxContent( $post, $args ) {
    if ( $parent_id = wp_is_post_revision($post->ID) ) 
      $post = get_post($parent_id);

    $shortlink = get_post_meta($post->ID, 'bitly_link');
    wp_nonce_field('bbp_generate_shortlink', 'bbp_generate_shortlink_nonce');
    
    if ( empty($shortlink) && ( empty($this->_settings['bitly_api_key']) || empty($this->_settings['bitly_username']) ) ) {
      echo '<p>Username and/or API key missing.</p>';
    }
    else if ( empty($shortlink) ) {
      if ( $post->post_status == 'publish' ) {
        echo '<a href="#" class="button" id="bbp_generate_shortlink_button" data-postid="'. $post->ID .'">Generate Shortlink Now</a>';
      }
      echo '<p>A shortlink will be generated when this post is published.</p>';
      echo '<input type="checkbox" id="bbp_no_shortlink" name="bbp_no_shortlink" value="true" /> ';
      echo '<label for="bbp_no_shortlink">Do not generate a shortlink for this post</label>';
    }
    else {
      echo '<input type="text" size="20" value="' . $shortlink[0] . '" disabled="disabled" />';
    }
    
    // Add 'Success' Message
    if ( isset($_GET['bbp_success']) && $_GET['bbp_success'] == '1' ) {
      echo '<div id="bbp_success" class="updated"><p>Bit.ly shortlink created!</p></div>';
    }
  }
  
  function addMetaBoxJS() {
    global $pagenow;
    
    if ( $pagenow == 'post.php' || $pagenow == 'post-new.php' )
      wp_enqueue_script('bbp_meta_box_js', plugins_url('/'. BBP_PLUGIN_SLUG .'.js', __FILE__), array('jquery'));
  }
  
  function autoShortlink( $new_status, $old_status, $post ) {
    // Action Hook: do_action('transition_post_status', $new_status, $old_status, $post);
  	// Ref: wp_transition_post_status() : wp-includes/post.php
  	// Ref: http://codex.wordpress.org/Post_Status_Transitions
    if ( !current_user_can('edit_posts') || $new_status != 'publish' || empty($this->_settings['bitly_api_key']) || empty($this->_settings['bitly_username']) || ( isset($_POST['bbp_no_shortlink']) && $_POST['bbp_no_shortlink'] == true ) )
      return;
    
    // Function dies if not referred from admin page
    check_admin_referer('bbp_generate_shortlink', 'bbp_generate_shortlink_nonce');
    
    if ( $parent_id = wp_is_post_revision($post->ID) ) 
      $post = get_post($parent_id);
    
    if ( $post->post_type != 'post' )
      // Ref: http://codex.wordpress.org/Post_Types
      return;
      
    if ( !$permalink = get_permalink($post->ID) )
      return;
    
    // Shorten Permalink
    if ( !$shortlink = $this->_getBitlyLink($permalink) )
      return;
    
    update_post_meta($post->ID, 'bitly_link', $shortlink);
    add_filter('redirect_post_location', array($this, 'addSuccessQueryArg'), 10, 2);
  }
  
  function manualShortlink() {
    $error_response = json_encode(array(
      'status' => 'error',
      'data'   => null
    ));
     
    header("Content-Type: application/json");
    
    if ( isset($_POST['postID']) && is_numeric($_POST['postID']) && isset($_POST['bbpNonce']) && check_ajax_referer('bbp_generate_shortlink', 'bbpNonce', false) && current_user_can('edit_posts') ) {
      $post = get_post($_POST['postID']);
      $permalink = get_permalink($post->ID);
      
      if ( $shortlink = $this->_getBitlyLink($permalink) ) {
        update_post_meta($post->ID, 'bitly_link', $shortlink);
        echo json_encode(array(
          'status' => 'success',
          'data'   => $shortlink
        ));
      }
      else {
        echo $error_response;
      }
    }
    else {
      echo $error_response;
    }
    exit;
  }
  
  function addSuccessQueryArg( $location, $post_id ) {
    // Filter Hook: wp_redirect( apply_filters( 'redirect_post_location', $location, $post_id ) );
    // Ref: redirect_post() : wp-admin/post.php
    return add_query_arg('bbp_success', true, $location);
  }
  
  function getShortlink( $shortlink, $id, $context, $allow_slugs ) {
    // Filter Hook: return apply_filters('get_shortlink', $shortlink, $id, $context, $allow_slugs);
  	// Ref: wp_get_shortlink() : wp-includes/link-template.php
    if ( empty($shortlink) || ($context != 'post' && $context != 'query') )
      return $shortlink;
      
    if ( $context == 'query' && !is_single() )
      return $shortlink;
    
    if ( !empty($id) ) {
      $post = get_post($id);
    }
    else {
      global $wp_query;
      $post = $wp_query->get_queried_object();
    }
    
    if ( !isset($post->post_type) || $post->post_type != 'post' )
      return $shortlink;
      
    $bitly_link = get_post_meta($post->ID, 'bitly_link');
    
    if ( !empty($bitly_link) )
      return $bitly_link[0];
    else
      return $shortlink;
  }

  private function _getBitlyLink( $permalink ) {
    $endpoint_url = "http://api.bit.ly/v3/shorten?login=" . $this->_settings['bitly_username'] . "&apiKey=" . $this->_settings['bitly_api_key'] . "&longUrl=" . urlencode( $permalink );
    $response = wp_remote_get($endpoint_url);

    if ( is_wp_error($response) )
      return false;

    $bitly_response = json_decode($response['body']);

    if ( $bitly_response->status_code != 200 )
      return false;

    return 'http://' . $this->_settings['bitly_domain'] . '/' . $bitly_response->data->hash;
  }
}

$bbb = new BubsBitlyPlugin();
?>