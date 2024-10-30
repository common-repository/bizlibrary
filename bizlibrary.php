<?php 

/*
Plugin Name:	BizLibrary
Plugin URI: 	http://garvinmedia.com
Description: 	Enables Single Sign-On via SSL for BizLibrary resources.
Author: 		Matthew Garvin
Author URI: 	http://garvinmedia.com
Version: 		1.1
Text Domain: 	biz-lib
*/


/**
 * vars
 *
 */
$bizlibrary_status = array('Available', 'In Progress', 'Completed');
$bizlibrary_score = range(0, 100);
$biz_admin = 'bizlibrary';



/**
 * schedules bizlibrary import hook
 *
 */
function bizlibrary_setup_schedule() {
	if ( ! wp_next_scheduled( 'bizautoimport' ) ) {
		wp_schedule_event( time(), 'daily', 'bizautoimport');
	}
}
add_action( 'wp', 'bizlibrary_setup_schedule' );



/** 
 * deactivates plugin
 * 
 * @since 2.0
 */
function bizlibrary_deactivate() {
	wp_clear_scheduled_hook( 'bizautoimport' );
	delete_option( 'biz_auto_status' ); 
}
register_deactivation_hook(__FILE__, 'bizlibrary_deactivate');

	
	
/** 
 * auto import of biz test data
 * 
 * @since 2.0
 */	
add_action('bizautoimport','bizlibrary_auto_import');
function bizlibrary_auto_import(){
	
	$settings = get_option( 'biz' ); //get saved options
	if($settings['schedule']){ //only try if selected
	
		$headers[] = 'From: '.get_option('blogname').' <'.get_option('admin_email').'>'; //email header
		$now_date = date(get_option('date_format').' '.get_option( 'time_format'), current_time('timestamp'));
	
		try{ // get file from biz
			$classes = bizlibrary_get_import_records();
			$count = bizlibrary_add_to_user_meta($classes);
			$message = sprintf(__('%d courses were imported during last cycle on %s', 'biz-lib' ), $count, $now_date);
		} catch (Exception $e) { 
			$message = sprintf(__('Automatic import error at %s: %s','biz-lib'), $now_date, $e->getMessage());
			update_option( 'biz_auto_status', $message ); // save error
			wp_mail( get_option('admin_email'), __('BizLibrary Import Error','biz-lib'), $message, $headers );
		}
		update_option( 'biz_auto_status', $message ); // save success message
		$settings = get_option( 'biz' ); // Read in options from database
		if($settings['notify']){ //do we want notifications?
			wp_mail( get_option('admin_email'), __('BizLibrary Auto-Import','biz-lib'), $message, $headers );
		}
	}
}



/** 
 * processes authentication request
 * 
 * @since 1.0
 */
function bizlibrary_single_sign_on(){
	if(isset($_GET['campus']) ){ //this url will lead us to the training
		try{
			$redirect_url = '/Site/Home';
			if(isset($_GET['biz_redirect'])){$redirect_url = $_GET['biz_redirect'];}
			bizlibrary_authenticate($redirect_url);
		} catch (Exception $e) {
			$current_user = wp_get_current_user();
			if ( ($current_user instanceof WP_User) ){
				$headers[] = 'From: <'.$current_user->user_email.'>';
				
				$message = printf(__('%s has experienced an error logging into BizLibrary.
				Email: %s
				Employee ID: %s','biz-lib'),
					$current_user->display_name, 
					$current_user->user_email,
					get_user_meta($current_user->ID, 'employee_id', true));
				wp_mail( get_option('admin_email'), __('BizLibrary Login Error','biz-lib'), $message, $headers );
			}
			wp_die($e->getMessage(), __('BizLibrary Login','biz-lib'), array('back_link'=>true));
		}
	}
}
add_action( 'init', 'bizlibrary_single_sign_on' );



/** 
 * authenticates user to bizlibrary
 * 
 * @attr string $redirect_url where to redirect after authenticating
 * 
 * @since 1.0
 */
function bizlibrary_authenticate($redirect_url = '/Site/Home'){
	$settings = get_option( 'biz' ); //get saved options
	$sso_url = 'https://www.companycollege.com/Services/SSO.aspx'; //sso url
	$username ='';
	$login_time = time();
	$first_name = '';
	
	//take username from currently logged in wordpress user
	$current_user = wp_get_current_user();
	if ( ($current_user instanceof WP_User) ){
		$username = $current_user->user_email;
		$first_name = $current_user->user_firstname;
	}
	$post_data = array(
		'accesskey' => $settings['key'],
		'accesssecret' => $settings['secret'],
		'look' => $settings['look'], 
		'redirect_url' => $redirect_url,  
		'username' => $username, 
		'time' => $login_time
	);
	$ch = curl_init( $sso_url );
	curl_setopt( $ch, CURLOPT_POST, 1); // enact a regular http POST operation
	curl_setopt( $ch, CURLOPT_POSTFIELDS, $post_data); // send along data
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1);
	if($response = curl_exec( $ch )){
		if(curl_getinfo($ch, CURLINFO_HTTP_CODE)=='200'){ // SSO successfull 
			header('Location: '.$response);	
			exit;
		}else{ // SSO error
			throw new Exception(
			sprintf(__('%, an error occured connecting to online training. Please try again.', 'biz-lib'), $first_name)
			);
		}
	}else{ // curl error
		throw new Exception( sprintf( __('Authorization error: %s','biz-lib'), curl_error($ch)));
	}
}



/** 
 * returns data from bizlibrary SFTP server.
 * 
 * @since 2.2
 */	
function bizlibrary_get_records(){
	include('phpseclib/SFTP.php'); //include phpseclib library
	
	$settings = get_option('biz'); //get saved options
	
	$sftp = new Net_SFTP($settings['address'], '5021'); //connect
	if (!$sftp->login($settings['login'], $settings['password'])) {
		throw new Exception(__('Error: Unable to connect to SFTP','biz-lib'));
	}
	if(!$get_file = $sftp->get($settings['file'])){
		throw new Exception( sprintf( __( 'Error: Unable to get file %s' , 'biz-lib' ), $settings['file'] ));
	}
	$csv = str_getcsv($get_file,"\n"); //get export file
	unset($csv[0]); //remove headers
	$classes = array(); //prepare return
	
	foreach($csv as $value){ //run through each record
		$class = str_getcsv( $value ); //turn each record into an array
		$classes[] = array( // build result set
			'email' => strtolower($class[1]),
			'title'	=> $class[2],
			'guid'	=> $class[4],
			'status'=> $class[5],
			'score'	=> $class[6],
			'date'	=> $class[7],
			'tic'	=> $class[8], //time in course
		);	
	}
	return $classes;
}



/** 
 * adds course data into user meta
 * 
 * @param array $classes training class data
 * @return $count number of records added, false on failure
 * @since 2.2
 */	
function bizlibrary_add_to_user_meta($classes){
	$count = 0; //number of imported classes
	foreach($classes as $class){ //run through each record
		if($user = get_user_by( 'email', $class['email'])){ //user exists in spark
			$existing_classes = get_user_meta( $user->ID, 'biz_library', true ); //user class data
			delete_user_meta( $user->ID, 'biz_library' ); //deletes existing user class meta
			$existing_classes[$class['guid']] = $class; //add this class
			// write new data with added class information
			if(add_user_meta( $user->ID, 'biz_library', $existing_classes, true)){
				$count++; //update count
			}
		}
	}
	return $count;
}



/** 
 * returns training from user meta
 * 
 * @param int $id user id
 * @param string $status training status 
 * @return array $classes 
 * 
 */	
function bizlibrary_get_training($id, $status='Available'){
	$classes = get_user_meta($id, 'biz_library', true);
	$class_return = array();
	if(is_array($classes)){
		foreach($classes as $class){
			if($class['status']==$status){
				$class_return[] = $class;				
			}
		}
	}
	return $class_return;
}



/** 
 * deletes one course from biz_library user meta array
 * 
 * @param int $user_id user id
 * @param string $guid training class guid 
 * @return bool true on save success, false on failure
 * @since 2.3
 */
function bizlibrary_delete_course($user_id, $guid){
	$classes = get_user_meta($user_id, 'biz_library', true); //get user classes
	unset($classes[$guid]); //remove class
	delete_user_meta( $user_id, 'biz_library' ); //deletes all class data
	if(add_user_meta( $user_id, 'biz_library', $classes, true)){return true;} // write new data with removed class
	return false;
}



/**
 * hooks settings page
 * 
 * @since 2.0
 */
function bizlibrary_create_menu() {
	global $biz_admin;
	$hook_suffix = add_options_page(__('BizLibrary','biz-lib'), 
		__('BizLibrary','biz-lib'), 'manage_options', $biz_admin, 'bizlibrary_settings');
	add_action( 'load-' . $hook_suffix , 'bizlibrary_load_settings' );	 //run on page load
}
add_action('admin_menu', 'bizlibrary_create_menu');



/**
 * runs on settings page load
 * 
 * @since 2.0
 */
function bizlibrary_load_settings() {
	if($schedule = wp_next_scheduled('bizautoimport')){ 
		$schedule += get_option( 'gmt_offset' ) * 3600; //get time
		$update = date(get_option('date_format').' '.get_option( 'time_format'), $schedule); //format date
		?>
		<div id="notice" class="updated fade">
			<p><?php printf( __('Next auto update to occur %s','biz-lib'), $update ); ?></p>
			<p><?php echo get_option( 'biz_auto_status' ); ?></p></div>
		</div>
	<?php
	}
}



/**
 * creates bizlibrary admin page frame
 * 
 * @since 2.0
 */
function bizlibrary_settings(){
	if (!current_user_can('manage_options')){ // authorized?
		wp_die( __('You do not have sufficient permissions to access this page.', 'biz-lib'));
	}
	global $biz_admin; //get page
	if(!isset($_GET['action'])){$_GET['action']='';} //action ?>
	<div class="wrap">
	<h2><?php _e( 'BizLibrary', 'biz-lib' );?></h2>
	<a class="button-secondary" href="?page=<?php echo $biz_admin; ?>">
		<?php _e('Settings','biz-lib');?></a> 
	<a class="button-secondary" href="?page=<?php echo $biz_admin; ?>&action=import">
		<?php _e('View Import File','biz-lib');?></a> 
	<a class="button-secondary" href="?page=<?php echo $biz_admin; ?>&action=user">
		<?php _e('View User Courses','biz-lib');?></a> 
	
	<form method="post" action="">
	<?php switch ($_GET['action']): case 'user':
		bizlibrary_settings_user(); // view user training
	break; case 'import':
		bizlibrary_settings_import(); // import file
	break; case 'edit':	
		bizlibrary_settings_edit(); //edit course
	break; default:
		bizlibrary_settings_general(); //general settings
	endswitch;?>
	</form>
</div>
<?php }



/**
 * creates settings pane
 * 
 * @since 2.0
 */
function bizlibrary_settings_general() {
	global $cron_schedule;
    
	if (!empty( $_POST ) && check_admin_referer('update_settings','biz')): //settings page submitted
		$settings = array( //build settings array
			'key'=> $_POST['key'],
			'secret'=> $_POST['secret'],
			'look'=> $_POST['look'],
			'file'=> $_POST['file'],
			'address'=> $_POST['address'],
			'login'=> $_POST['login'],
			'password'=> $_POST['password'],
			'notify'=> $_POST['notify'],
			'schedule'=> $_POST['schedule']
		);
		
		update_option( 'biz', $settings ); // save settings in database ?>
		<div class="updated"><p><strong><?php _e('Settings saved.', 'biz-lib' ); ?></strong></p></div>
	<?php endif;
	$settings = get_option( 'biz' ); // Read in options from database
	
	echo wp_nonce_field( 'update_settings', 'biz'); ?>
	<h3><?php _e('Single Sign-On','biz-lib');?></h3>	
	<table class="form-table">
	<tr valign="top">
		<th scope="row"><?php _e('Access Key', 'biz-lib' ); ?></th>
		<td><input type="text" name="key" value="<?php echo $settings['key']; ?>" /></td>
	</tr>
	<tr valign="top">
		<th scope="row"><?php _e('Access Secret', 'biz-lib' ); ?></th>
		<td><input type="text" name="secret" value="<?php echo $settings['secret']; ?>" /></td>
	</tr>
	<tr valign="top">
		<th scope="row"><?php _e('Branded Site Code', 'biz-lib' ); ?></th>
		<td><input type="text" name="look" value="<?php echo $settings['look']; ?>" /></td>
	</tr>	
	</table>	
	<h3><?php _e('Data Import','biz-lib');?></h3>
	<table class="form-table">
	<tr valign="top">
		<th scope="row"><?php _e('File Name', 'biz-lib' ); ?></th>
		<td><input type="text" name="file" value="<?php echo $settings['file']; ?>" /></td>
	</tr>
	<tr valign="top">
		<th scope="row"><?php _e('SFTP Address', 'biz-lib' ); ?></th>
		<td><input type="text" name="address" value="<?php echo $settings['address']; ?>" /></td>
	</tr>
	<tr valign="top">
		<th scope="row"><?php _e('SFTP Login', 'biz-lib' ); ?></th>
		<td><input type="text" name="login" value="<?php echo $settings['login']; ?>" /></td>
	</tr>
	<tr valign="top">
		<th scope="row"><?php _e('SFTP Password', 'biz-lib' ); ?></th>
		<td><input type="text" name="password" value="<?php echo $settings['password']; ?>" /></td>
	</tr>
	<tr valign="top">
		<th scope="row"><?php _e('Notify Administrator On Import','biz-lib');?></th>
		<td>
		<input type="hidden" name="notify" />
		<input type="checkbox" name="notify" value="1" <?php checked($settings['notify']); ?> /></td>
	</tr>
	<tr valign="top">
		<th scope="row"><?php _e('Enable Automatic Import','biz-lib');?></th>
		<td>
		<input type="hidden" name="schedule" />
		<input type="checkbox" name="schedule" value="1" <?php checked($settings['schedule']); ?> />
		</td>
	</tr>
	</table>
	<p class="submit">
		<input type="submit" class="button-primary" value="<?php esc_attr_e('Save Changes','biz-lib') ?>" />
	</p>
<?php
}



/**
 * creates import pane
 *
 * @since 2.3
 */
function bizlibrary_settings_import(){
	
	$settings = get_option( 'biz' ); // get options file 
	$classes = array(); //set up classes
	
	try{ //get training records on bizlibrary
		$classes = bizlibrary_get_records();
	} catch (Exception $e) { 
		echo '<div class="error"><p>'.$e->getMessage().'</p></div>'; //error getting records
	}
	
	if (!empty( $_POST['biz'] ) && check_admin_referer('import_training','biz')): // submitted
		$count = bizlibrary_add_to_user_meta($classes);
		$import = date(get_option('date_format').' '.get_option( 'time_format'), current_time('timestamp'));
		$message = sprintf(__(' %d records imported to %s at %s.','biz-lib'), $count, get_option('blogname'), $import);
		echo '<div class="updated"><p>'.$message.'</p></div>';
	endif; ?>
		
	<h3><?php printf(__('%d records on BizLibrary SFTP site.','biz-lib'), count($classes));?></h3>
	<?php echo wp_nonce_field( 'import_training', 'biz'); //nonce ?>
	<table class="widefat">
		<tr><th><?php _e('User','biz-lib');?></th>
		<th><?php _e('Title','biz-lib');?></th>
		<th><?php _e('Date','biz-lib');?></th>
		<th><?php _e('Time In Course','biz-lib');?></th>
		<th><?php _e('Status','biz-lib');?></th>
		<th><?php _e('Score','biz-lib');?></th></tr>
		<?php foreach($classes as $class):?>
			<tr><td><?php echo $class['email'];?></td>
			<td><?php echo $class['title'];?></td>
			<td><?php echo $class['date'];?></td><td><?php echo $class['tic'];?></td>
			<td><?php echo $class['status'];?></td><td><?php echo $class['score'];?></td></tr>
		<?php endforeach; ?>	
	</table>
	<p class="submit">
		<input type="submit" class="button-primary" value="<?php _e('Import Records','biz-lib');?>" />
	</p>
		
	<?php
}



/**
 * create user pane
 * 
 * @since 2.3
 */
function bizlibrary_settings_user(){
	global $biz_admin;
	$user_submitted = '';
	$classes = array();
	
	if(isset($_GET['delete']) && check_admin_referer( 'delete-course_'.$_GET['guid'], 'biz')){
		if( bizlibrary_delete_course( $_GET['user_id'], $_GET['guid'])){
			echo '<div class="updated"><p>'.__('Course Deleted', 'biz-lib').'</p></div>';
		}
	}
	if (isset( $_REQUEST['user'] )){
		if($user = get_user_by( 'login', $_REQUEST['user'])){
			$classes = get_user_meta($user->ID, 'biz_library', true); //get classes
			$user_submitted = $_REQUEST['user'];
		}else{
			echo '<div class="error"><p>'.__('User not found','biz-lib').'</p></div>';
		}
	}?>
	
	<h3><?php _e('View User Courses','biz-lib'); ?></h3>
	<table class="form-table"><tr valign="top">
		<th scope="row"><?php _e('Username:','biz-lib');?></th>
        <td><input type="text" name="user" value="<?php echo $user_submitted; ?>" />
		<input type="submit" name="import" class="button-primary" value="View Courses" /></td>
	</tr></table>
		
	<?php if($classes):?>		
		<table class="widefat">
		<tr><th></th><th><?php _e('Title','biz-lib');?></th>
		<th><?php _e('Status','biz-lib');?></th>
		<th><?php _e('Score','biz-lib');?></th>
		<th><?php _e('Date','biz-lib');?></th>
		<th><?php _e('Time In Course','biz-lib');?></th>
		</tr>
		<?php foreach($classes as $class): 
		
			$bare_url = '?page='.$biz_admin.'&action=user&delete=true&user='.$user->user_login.'&user_id='.$user->ID.'&guid='.$class['guid'];
			$delete_url = wp_nonce_url( $bare_url, 'delete-course_'.$class['guid'], 'biz' ); ?>
		
			<tr><td><a href="?page=<?php echo $biz_admin; ?>&action=edit&user_id=<?php echo $user->ID;?>&guid=<?php echo $class['guid']; ?>"><?php _e( 'Edit','biz-lib' ); ?></a> | 
			<a onclick="return confirm('<?php _e('Delete course record?','biz-lib');?>')" href="<?php echo $delete_url; ?>" ><?php _e('Delete','biz-lib');?></a></td>
			<td><?php echo $class['title'];?></td>
			<td><?php echo $class['status'];?></td>
			<td><?php echo $class['score'];?></td>
			<td><?php echo $class['date'];?></td>
			<td><?php if(isset($class['tic'])){echo $class['tic'];}?></td></tr>
		<?php endforeach; ?>
		</table>	
	<?php endif;
}



/**
 * create edit pane
 * 
 * @since 2.3
 */
function bizlibrary_settings_edit(){
	
	global $bizlibrary_status, $bizlibrary_score;
		
	$classes = get_user_meta($_REQUEST['user_id'], 'biz_library', true);
	
	if (!empty( $_POST['biz'] ) && check_admin_referer('edit_course','biz')): // submitted
		$classes[$_REQUEST['guid']]['status'] = $_POST['status']; // new status
		$classes[$_REQUEST['guid']]['score'] = $_POST['score']; //new score
		delete_user_meta( $_REQUEST['user_id'], 'biz_library' ); //deletes existing class data
		add_user_meta( $_REQUEST['user_id'], 'biz_library', $classes, true); //updates new class data
		echo '<div class="updated"><p>'.__('Course Updated', 'biz-lib').'</p></div>';
	endif;
	
	$class = $classes[$_REQUEST['guid']]; //get class to edit the updated values ?>
	
	<h3><?php _e('Edit Course','biz-lib'); ?></h3>
	
	<?php echo wp_nonce_field( 'edit_course', 'biz'); ?>
	<input type="hidden" name="user_id" value="<?php echo $_REQUEST['user_id']?>" />
	<input type="hidden" name="guid" value="<?php echo $_REQUEST['guid']?>" />
	<table class="widefat"><tr>
	<th>Title</th><th>Status</th><th>Score</th><th>Date</th><th>Time In Course</th></tr>
	<tr><td><?php echo $class['title'];?></td>
	<td><?php echo bizlibrary_select_field('status', $bizlibrary_status, $class['status']); ?></td>
	<td><?php echo bizlibrary_select_field('score', $bizlibrary_score, $class['score']); ?></td>
	<td><?php echo $class['date'];?></td>
	<td><?php if(isset($class['tic'])){echo $class['tic'];}?></td>
	</tr></table>
	<p><input type="submit" class="button-primary" value="<?php _e('Update','biz-lib');?>" /></p>	
<?php }



/**
 * create general select field
 *
 */
function bizlibrary_select_field($name, $values, $selected=NULL){?>
	<select name="<?php echo $name;?>">
		<?php foreach($values as $value):
			echo '<option value="'.$value.'" '.($value == $selected ? 'selected' : '').'>'.$value.'</option>';
		 endforeach; ?>
	</select>
<?php }