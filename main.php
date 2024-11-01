<?php
/*
Plugin Name: WP Copyright
Plugin Uri: https://wordpress.org/plugins/wp-copyright
Description: Enforces copyright discipline by blurring all uploaded images as long as copyright info is undefined.
Version: 2.0.4
Author: zitrusblau.de
Author URI: https://www.zitrusblau.de
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html
Text Domain: wp-copyright
*/

define('ZB_WPC_DEFAULT_LOCKED_PERMISSIONS', '0600');
define('ZB_WPC_DEFAULT_RELEASED_PERMISSIONS', '0644');
define('ZB_WPC_DEFAULT_COPYRIGHT_META_KEY', 'copyright');

register_activation_hook( __FILE__, 'zb_wpc_activate_plugin' );
register_deactivation_hook( __FILE__, 'zb_wpc_deactivate_plugin' );

/**
 * [zb_wpc_activate_plugin description]
 * @return [type] [description]
 */
function zb_wpc_activate_plugin() {

  $options = array('zb_wpc_copyright_meta_key' => ZB_WPC_DEFAULT_COPYRIGHT_META_KEY,
                   'zb_wpc_locked_permissions' => ZB_WPC_DEFAULT_LOCKED_PERMISSIONS,
                   'zb_wpc_released_permissions' => ZB_WPC_DEFAULT_RELEASED_PERMISSIONS,
                   'zb_wpc_exif_copyright' => ZB_WPC_DEFAULT_COPYRIGHT_META_KEY);

  if(!add_option( 'zb_wp_copyright_options', $options)) {
    error_log("Warning: Cannot add wp copyright options", 0);
  }

  set_transient( 'zb_wpc_admin_notice', true, 5 );
}

add_action( 'admin_notices', 'zb_wpc_activation_notice' );

/**
 * [zb_wpc_activation_notice description]
 * @return [type] [description]
 */
function zb_wpc_activation_notice(){

  if( get_transient( 'zb_wpc_admin_notice' ) ){
      ?>
      <div class="updated notice is-dismissible">
          <p>Thank you for using WP Copyright! Please check the <a href="<?php echo admin_url( $path = 'options-general.php?page=zb-wp-copyright-admin'); ?>">WP Copyright Settings</a> <i>before</i> uploading any media.</p>
      </div>
      <?php

      delete_transient( 'zb_wpc_admin_notice' );
  }

}

/**
 * [zb_wpc_deactivate_plugin description]
 * @return [type] [description]
 */
function zb_wpc_deactivate_plugin() {
  if(!delete_option( 'zb_wp_copyright_options' )) {
    error_log("WARNING: Cannot delete wp copyright options", 0);
  }
}

/**
 * Add copyright field to attachment details
 * @var [type]
 */
add_filter('attachment_fields_to_edit', function ($form_fields, $post) {

  // assure file uploads from download-monitor plugin don't display this input
  if( strpos($post->guid, 'dlm_uploads') !== false ) {
    return $form_fields;
  }

  $field_value = get_post_meta($post->ID, 'copyright', true);
  $form_fields['copyright'] = array(
    'value' => $field_value ? $field_value : '',
    'label' => __('Copyright', 'wp-copyright'),
    'helps' => __('The copyright information for this image (Required to remove pixelation)', 'wp-copyright'),
    'show_in_edit' => true,
    'show_in_modal' => true,
  );
  return $form_fields;

}, 9, 2);

/**
 * Save copyright
 * @var [type]
 */
add_action('edit_attachment', function ($attachment_id) {
    if (isset($_REQUEST['attachments'][$attachment_id]['copyright'])) {
        $copyright = sanitize_text_field($_REQUEST['attachments'][$attachment_id]['copyright']);
        $updated = update_post_meta($attachment_id, 'copyright', $copyright);
    }
}, 10, 1);

/**
 * Move / Revocer from vault
 * @var [type]
 */
add_action('edit_attachment', function ($post_id) {

    $copyright_meta_key = zb_wpc_get_copyright_meta_key();
    $has_copyright = get_post_meta( $post_id, $copyright_meta_key, true );


    if (!$has_copyright or empty($has_copyright) or is_null($has_copyright)) {
        // Not required, me think
      $moved = zb_wpc_move_to_vault($post_id);

    } else {
        $recovered = zb_wpc_recover_from_vault($post_id);
    }
}, 10, 1);

/**
 * (Optional) In case of removed copyright
 * @param  [type] $post_id [description]
 * @return [type]          [description]
 */
function zb_wpc_move_to_vault($post_id)
{

  if(zb_wpc_is_protected($post_id)) {
    return false;
  }

  // Do not filter DLM Downloads
  $parent_id = wp_get_post_parent_id( $post_id );
  if($parent_id and get_post_type( $parent_id ) === 'dlm_download') {
    return false;
  }

  $copyright_meta_key = zb_wpc_get_copyright_meta_key();

  // Checkup
  $has_copyright = get_post_meta($post_id, $copyright_meta_key, true);
  if ($has_copyright and !empty($has_copyright) and !is_null($has_copyright)) {
      return false;
  }

  $data = wp_get_attachment_metadata( $post_id);

  if (!$data or !isset($data['sizes'])) {
      return false;
  }

  // Check Metadata
  $exif_copyright_key = zb_wpc_get_exif_copyright_key();

  if(isset($data['image_meta'][$exif_copyright_key]) and !empty($data['image_meta'][$exif_copyright_key])) {
    $updated = update_post_meta( $post_id, $copyright_meta_key, $data['image_meta'][$exif_copyright_key] );
    return update_post_meta($post_id, 'released', 1);
  }

  // MAIN PROTECTION SEQUENCE

  // => Create blurry copies

  $upload_dir = dirname(get_attached_file($post_id));
  $upload_root_dir = WP_CONTENT_DIR.DIRECTORY_SEPARATOR.'uploads';

  /**
   * create path for blurry copy
   * @var [type]
   */
  $blurry_output_path = function($path, $extension) {
    return substr($path, 0, (strlen($path) - strlen($extension) -1)).'-blur.'.$extension;
  };

  /**
   * get file extension or default
   * @var [type]
   */
  $get_extension = function($path) {
    if(is_file($path)) {
      return isset(pathinfo($path)['extension']) ? pathinfo($path)['extension'] : 'png';
    }
    return 'png';
  };

  // create blurry original
  if(zb_wpc_blur($upload_root_dir.DIRECTORY_SEPARATOR.$data['file'],
                 $blurry_output_path($upload_root_dir.DIRECTORY_SEPARATOR.$data['file'],
                                     $get_extension($upload_root_dir.DIRECTORY_SEPARATOR.$data['file']) ))) {
      $new_file = $blurry_output_path($data['file'],
                                      $get_extension($upload_root_dir.DIRECTORY_SEPARATOR.$data['file']));
  } else {
    error_log('Blurring original failed', 0);
    return $data;
  }

  if(!update_attached_file( $post_id, $new_file)) {
    error_log("Warning: Attached file not updated", 0);
  }

  // create blurry images for all sizes
  $new_sizes = array_map(function($array) use($upload_dir, $blurry_output_path, $get_extension) {

    if(zb_wpc_blur($upload_dir.DIRECTORY_SEPARATOR.$array['file'],
                   $blurry_output_path($upload_dir.DIRECTORY_SEPARATOR.$array['file'],
                                       $get_extension($upload_dir.DIRECTORY_SEPARATOR.$array['file']) ))) {

      return array_replace($array, array('file' => $blurry_output_path($array['file'],
                                                   $get_extension($upload_dir.DIRECTORY_SEPARATOR.$array['file']))));
    }
    return $array;

  }, $data['sizes']);

  $new_permissions = zb_wpc_get_locked_permissions();

  // Change permission of original
  chmod($upload_root_dir.DIRECTORY_SEPARATOR.$data['file'], $new_permissions);

  // Change permissions for all sizes
  array_walk($data['sizes'], function($array) use($upload_dir, $new_permissions) {
    if(!chmod($upload_dir.DIRECTORY_SEPARATOR.$array['file'], $new_permissions)) {
      error_log("Permission changes failed", 0);
    }
  });

  // Finalize
  if(!wp_update_attachment_metadata( $post_id, array_replace($data, array('sizes' => $new_sizes,
                                    'file' => $new_file) ))) {
                                        error_log("Warning: Update of attachment metadata failed", 0);
                                        return false;
                                    }

  return update_post_meta($post_id, 'released', 0);
}


add_action('wp_ajax_check_vault', 'zb_wpc_check_vault_ajax');
add_action('wp_ajax_nopriv_check_vault', 'zb_wpc_check_vault_ajax');

/**
 * Check if attachment is released
 * @return [type] [description]
 */
function zb_wpc_check_vault_ajax()
{
    if (!isset($_REQUEST['id']) or empty($_REQUEST['id'])) {
        wp_send_json_error();
    }

    $post_id = intval($_REQUEST['id']);
    if (!$post_id) {
        wp_send_json_error();
    }

    if(!get_post_meta($post_id, 'released', true)) {
      return wp_send_json_error();
    }

    wp_send_json_success(get_post_meta($post_id, 'released', true));
}

/**
 * Wherever the Media Modal is deployed, also deploy our overrides.
 */
add_action('wp_enqueue_media', function () {
    add_action('admin_print_footer_scripts', 'zb_wpc_override_media_templates', 11);
});

/**
 * [override_media_templates description]
 * @return [type] [description]
 */
function zb_wpc_override_media_templates()
{
    ?>
<script type="text/html" id="tmpl-copyright-refresh">
  <?php echo zb_wpc_get_attachment_display_extension(); ?>
</script>

<script type="text/javascript">
(function($) {

  $(document).ready( function() {

      /*var attDisplayOld = wp.media.view.Settings.AttachmentDisplay;
      wp.media.view.Settings.AttachmentDisplay = attDisplayOld.extend({
          template:  wp.template('copyright-refresh')
      });*/

      // Workaround: use underscore template to inject script - don't replace attachment settings
      var copyrightDisplay = wp.media.View.extend({
          //className: 'view-copyright',
          template: wp.media.template('copyright-refresh')
      });

  });

})(jQuery);


    </script>
  <?php

}

/**
 * Prepare attachment display extension as string
 * @return [type] [description]
 */
function zb_wpc_get_attachment_display_extension()
{
    ob_start(); ?>
  <script type="text/javascript">

  function zb_wpc_getPathFromUrl(url) {

    if(typeof url == "undefined") {
      return "/";
    }

    return url.split("?")[0];
  }

  (function($) {

    /// ***
    /// Check if vault processing has been finished
    ///
    function zb_wpc_query_vault(data_id, no_copyright) {

      if(typeof data_id == 'undefined') {
        return;
      }

      // Show loading inidicator

      var src = $('li.attachment.selected img').attr('src');

      if(src !== '<?php echo includes_url() . '/images/spinner.gif'; ?>') {
        $('li.attachment.selected img').attr('src', '<?php echo includes_url() . '/images/spinner.gif'; ?>');
      }

      $.ajax({
        url: ajaxurl,
        data: {
            action: 'check_vault',
            id: data_id
        },
        type: 'GET',
        success: function(response) {

          if(response.success) {
            if(!zb_wpc_is_blurry() && !no_copyright) {
              // Dp nothing
            } else {
              zb_wpc_refresh_media_sidebar();
            }

          } else {
              setTimeout(zb_wpc_query_vault, 200, data_id);
          }

        }
        });
    }

    /**
     * media sidebar image
     * @return [type] [description]
     */
    function zb_wpc_refresh_media_sidebar() {
      if(zb_wpc_is_blurry()) {
        $('.media-sidebar .attachment-info .thumbnail img').attr("src", zb_wpc_get_unblurred_src($('.media-sidebar .attachment-info .thumbnail img').attr('src')));
      } else {
        $('.media-sidebar .attachment-info .thumbnail img').attr("src", zb_wpc_get_blurred_src($('.media-sidebar .attachment-info .thumbnail img').attr('src')));
      }
    }

    /**
     * check if protected
     * @return boolean [description]
     */
    function zb_wpc_is_blurry() {
      return ($('.media-sidebar .attachment-info .thumbnail img').attr("src").match("-blur\.[a-z]+$") != null);
    }

    /**
     * create unblurred path from blurred src
     * @param  [type] src [description]
     * @return [type]     [description]
     */
    function zb_wpc_get_unblurred_src(src) {
      return src.substring(0, (src.length - ("-blur".length + 1) - zb_wpc_get_file_extension(src).length)) + '.' + zb_wpc_get_file_extension(src);
    }

    /**
     * create blurred path from blurred src
     * @param  [type] src [description]
     * @return [type]     [description]
     */
    function zb_wpc_get_blurred_src(src) {
      return src.substring(0, (src.length - (zb_wpc_get_file_extension(src).length + 1))) + '-blur.' + zb_wpc_get_file_extension(src);
    }

    /**
     * try to get potential file extension
     * @return [type] [description]
     */
    function zb_wpc_get_file_extension(filename) {
      return filename.split('.').pop();
    }

    /**
     * Detector
     */
    $('.media-sidebar :input[type="text"]').change(function(){

      if(typeof $(this).attr('id') === 'undefined' || $(this).attr('id').indexOf('copyright') === -1) {
        // Not da Mama
        return;
      }

      $id = $('li.attachment.selected').data('id');
      if($(this).val() == "") {
        zb_wpc_query_vault($id, true);
      } else {
        zb_wpc_query_vault($id, false);
      }

    });


  })(jQuery);

  </script>
  <?php
  $script = ob_get_contents();
    ob_end_clean();
    return $script;
}

/**
 * [add_filter description]
 * @var [type]
 */
add_filter('wp_prepare_attachment_for_js', function ($response, $attachment, $meta) {
    $script = zb_wpc_get_attachment_display_extension();

    $response['compat']['item'] = $response['compat']['item'].$script;
    return $response;
}, 10, 3);


/**
 * No cache headers for images in admin area
 * @var [type]
 */
add_action('admin_init', function () {
    nocache_headers();
});

/**
 *
 * @return boolean [description]
 */
function zb_wpc_is_protected($post_id) {
  return preg_match('/-blur\.[a-z]+$/', get_attached_file($post_id) ) === 1 ? true : false;
}

/**
 * [zb_wpc_is_blurred description]
 * @return boolean [description]
 */
function zb_wpc_is_blurred($path) {
  return preg_match('/-blur\.[a-z]+$/', $path) === 1 ? true : false;
}

/**
 * MAIN RECOVERY SEQUENCE
 * @param  [type] $post_id [description]
 * @return [type]          [description]
 */
function zb_wpc_recover_from_vault($post_id)
{

    if(get_post_meta($post_id, 'released', true) == 1) {
      // bail early if already released
      return;
    }

    if(!zb_wpc_is_protected($post_id)) {
      return false;
    }

    $upload_root_dir = WP_CONTENT_DIR.DIRECTORY_SEPARATOR.'uploads';
    $upload_dir = dirname(get_attached_file($post_id));

    $data = wp_get_attachment_metadata( $post_id );

    if(!$data) {
      return false;
    }

    /**
     * try to get extension string from file path
     * @var [type]
     */
    $get_extension = function($path) {
      if(is_file($path)) {
        return isset(pathinfo($path)['extension']) ? pathinfo($path)['extension'] : false;
      }
      return false;
    };

    // Prefetch extension
    $extension = $get_extension($upload_root_dir.DIRECTORY_SEPARATOR.$data['file']);

    if(!$extension) {
      error_log("Warning: Extension not found", 0);
      return false;
    }

    // 1. Cleanup

    // => Cleanup original
    if(is_file($upload_root_dir.DIRECTORY_SEPARATOR.$data['file']) && zb_wpc_is_blurred($data['file']) && !unlink($upload_root_dir.DIRECTORY_SEPARATOR.$data['file'])) {
      error_log("Removal of blurred image failed", 0);
    }

    // Cleanup all sizes
    array_walk($data['sizes'], function($array) use($upload_dir) {
      if(is_file($upload_dir.DIRECTORY_SEPARATOR.$array['file']) && zb_wpc_is_blurred($array['file']) && !unlink($upload_dir.DIRECTORY_SEPARATOR.$array['file'])) {
        error_log("Removal of blurred image failed", 0);
      }
    });

    // 2. Rename

      /**
     * get obvious file tpath without -blur
     * @var [type]
     */
    $unblur = function($path, $extension) {
      return substr($path, 0, (strlen($path) - (strlen($extension) + 1) - strlen("-blur") ) ).".$extension";
    };

    // Rename original
    $data['file'] = $unblur($data['file'], $extension);

    if(!update_attached_file( $post_id, $data['file'])) {
      error_log("Warning: Attached file not updated", 0);
    }

    // Rename all sizes
    $data['sizes'] = array_map(function($array) use($upload_dir, $unblur, $extension) {
        return array_replace($array, array('file' => $unblur($array['file'], $extension) ));
    }, $data['sizes']);

    // 3. Make public

    $new_permissions = zb_wpc_get_released_permissions();

    // => Original
    if(!chmod($upload_root_dir.DIRECTORY_SEPARATOR.$data['file'], $new_permissions)) {
      error_log("Warning: Cannot make media upload public again", 0);
    }

    // => All sizes
    array_walk($data['sizes'], function($array) use($upload_dir, $new_permissions) {
      if(!chmod($upload_dir.DIRECTORY_SEPARATOR.$array['file'], $new_permissions)) {
        error_log("Warning: Cannot make media upload public again", 0);
      }
    });

    // Update
    if(!wp_update_attachment_metadata( $post_id, $data )) {
      error_log("Warning: Update of metadata failed", 0);
    }

    return update_post_meta($post_id, 'released', 1);
}

/**
 * [add_filter description]
 * @var [type]
 */
add_filter('media_send_to_editor', function ($html, $send_id, $attachment) {
    $timestamp = current_time('timestamp');

    $html = str_replace('.jpg', '.jpg?'.$timestamp, $html);
    $html = str_replace('.png', '.png?'.$timestamp, $html);
    $html = str_replace('.gif', '.gif?'.$timestamp, $html);

    return $html;
}, 10, 3);

/**
 *
 * @return [type] [description]
 */
function zb_wpc_get_copyright_meta_key() {
  $options = get_option( 'zb_wp_copyright_options');

  if($options && isset($options['zb_wpc_copyright_meta_key']) && !empty($options['zb_wpc_copyright_meta_key'])) {
      return $options['zb_wpc_copyright_meta_key'];
  } else {
    return ZB_WPC_DEFAULT_COPYRIGHT_META_KEY;
  }
}

/**
 *
 * @return [type] [description]
 */
function zb_wpc_get_exif_copyright_key() {
  $options = get_option( 'zb_wp_copyright_options');

  if($options && isset($options['zb_wpc_exif_copyright']) && !empty($options['zb_wpc_exif_copyright'])) {
      return $options['zb_wpc_exif_copyright'];
  } else {
    return ZB_WPC_DEFAULT_COPYRIGHT_META_KEY;
  }
}

/**
 *
 * @return [type] [description]
 */
function zb_wpc_get_locked_permissions() {
  $options = get_option( 'zb_wp_copyright_options');

  if($options && isset($options['zb_wpc_locked_permissions']) && !empty($options['zb_wpc_locked_permissions'])) {
      return octdec($options['zb_wpc_locked_permissions']);
  } else {
    return ZB_WPC_DEFAULT_LOCKED_PERMISSIONS;
  }
}

/**
 *
 * @return [type] [description]
 */
function zb_wpc_get_released_permissions() {
  $options = get_option( 'zb_wp_copyright_options');

  if($options && isset($options['zb_wpc_released_permissions']) && !empty($options['zb_wpc_released_permissions'])) {
      return octdec($options['zb_wpc_released_permissions']);
  } else {
    return ZB_WPC_DEFAULT_RELEASED_PERMISSIONS;
  }
}

/**
 * [add_filter description]
 * @var [type]
 */
add_filter('wp_generate_attachment_metadata', function ($data, $post_id) {

  if(zb_wpc_is_protected($post_id)) {
    return $data;
  }

  // Do not filter DLM Downloads
  $parent_id = wp_get_post_parent_id( $post_id );
  if($parent_id and get_post_type( $parent_id ) === 'dlm_download') {
    return $data;
  }

  $copyright_meta_key = zb_wpc_get_copyright_meta_key();

  // Checkup
  $has_copyright = get_post_meta($post_id, $copyright_meta_key, true);
  if ($has_copyright and !empty($has_copyright) and !is_null($has_copyright)) {
      return $data;
  }

  if (!isset($data['sizes'])) {
      return $data;
  }

  // Check Metadata
  $exif_copyright_key = zb_wpc_get_exif_copyright_key();

  if(isset($data['image_meta'][$exif_copyright_key]) and !empty($data['image_meta'][$exif_copyright_key])) {
    $updated = update_post_meta( $post_id, $copyright_meta_key, $data['image_meta'][$exif_copyright_key] );
    return $data;
  }

  // MAIN PROTECTION SEQUENCE

  // => Create blurry copies

  $upload_dir = dirname(get_attached_file($post_id));
  $upload_root_dir = WP_CONTENT_DIR.DIRECTORY_SEPARATOR.'uploads';

  /**
   * create path for blurry copy
   * @var [type]
   */
  $blurry_output_path = function($path, $extension) {
    return substr($path, 0, (strlen($path) - strlen($extension) -1)).'-blur.'.$extension;
  };

  /**
   * get file extension or default
   * @var [type]
   */
  $get_extension = function($path) {
    if(is_file($path)) {
      return isset(pathinfo($path)['extension']) ? pathinfo($path)['extension'] : 'png';
    }
    return 'png';
  };

  // create blurry original
  if(zb_wpc_blur($upload_root_dir.DIRECTORY_SEPARATOR.$data['file'],
                 $blurry_output_path($upload_root_dir.DIRECTORY_SEPARATOR.$data['file'],
                                     $get_extension($upload_root_dir.DIRECTORY_SEPARATOR.$data['file']) ))) {
      $new_file = $blurry_output_path($data['file'],
                                      $get_extension($upload_root_dir.DIRECTORY_SEPARATOR.$data['file']));
  } else {
    error_log('Blurring original failed', 0);
    return $data;
  }

  if(!update_attached_file( $post_id, $new_file)) {
    error_log("Warning: Attached file not updated", 0);
  }

  // create blurry images for all sizes
  $new_sizes = array_map(function($array) use($upload_dir, $blurry_output_path, $get_extension) {

    if(zb_wpc_blur($upload_dir.DIRECTORY_SEPARATOR.$array['file'],
                   $blurry_output_path($upload_dir.DIRECTORY_SEPARATOR.$array['file'],
                                       $get_extension($upload_dir.DIRECTORY_SEPARATOR.$array['file']) ))) {

      return array_replace($array, array('file' => $blurry_output_path($array['file'],
                                                   $get_extension($upload_dir.DIRECTORY_SEPARATOR.$array['file']))));
    }
    return $array;

  }, $data['sizes']);

  $new_permissions = zb_wpc_get_locked_permissions();

  // Change permission of original
  chmod($upload_root_dir.DIRECTORY_SEPARATOR.$data['file'], $new_permissions);

  // Change permissions for all sizes
  array_walk($data['sizes'], function($array) use($upload_dir, $new_permissions) {
    if(!chmod($upload_dir.DIRECTORY_SEPARATOR.$array['file'], $new_permissions)) {
      error_log("Permission changes failed", 0);
    }
  });

  // Init or override flag released
  update_post_meta($post_id, 'released', 0);

  // Return modified metadata
  return array_replace($data, array('sizes' => $new_sizes,
                                    'file' => $new_file));

}, 99, 2);

/*
* image - the location of the image to pixelate
* pixelate_x - the size of "pixelate" effect on X axis (default 10)
* pixelate_y - the size of "pixelate" effect on Y axis (default 10)
* output - the name of the output file (extension will be added)
*/

if (function_exists('imagecreatefromjpeg') and
   function_exists('imagecreatefrompng') and
   function_exists('imagecreatefromgif') and
   function_exists('imagejpeg') and
   function_exists('imagepng') and
   function_exists('imagegif')) {
    function zb_wpc_blur($image, $output, $pixelate_x = 15, $pixelate_y = 15)
    {
        // check if the input file exists
      if (!file_exists($image)) {
          echo 'File "'. $image .'" not found';
      }

      // get the input file extension and create a GD resource from it
      $ext = pathinfo($image, PATHINFO_EXTENSION);
        if ($ext == "jpg" || $ext == "jpeg") {
            $img = @imagecreatefromjpeg($image);
        } elseif ($ext == "png") {
            $img = @imagecreatefrompng($image);
        } elseif ($ext == "gif") {
            $img = @imagecreatefromgif($image);
        } else {
            error_log('Warning: Unsupported file extension', 3);
        }

        if (!$img) {
            return false;
        }

      // now we have the image loaded up and ready for the effect to be applied
      // get the image size
      $size = getimagesize($image);

        if (!$size) {
            return false;
        }

        $height = $size[1];
        $width = $size[0];

        $pixelate_x = round($width / 100) * $pixelate_x;
        $pixelate_y = round($height / 100) * $pixelate_y;

      // start from the top-left pixel and keep looping until we have the desired effect
      for ($y = 0;$y < $height;$y += $pixelate_y+1) {
          for ($x = 0;$x < $width;$x += $pixelate_x+1) {
              // get the color for current pixel
              $rgb = imagecolorsforindex($img, imagecolorat($img, $x, $y));

              // get the closest color from palette
              $color = imagecolorclosest($img, $rgb['red'], $rgb['green'], $rgb['blue']);
              imagefilledrectangle($img, $x, $y, $x+$pixelate_x, $y+$pixelate_y, $color);
          }
      }

        if (!$img) {
            return false;
        }

      // SAVE
      if ($ext == "jpg" || $ext == "jpeg") {
          imagejpeg($img, $output);
      } elseif ($ext == "png") {
          imagepng($img, $output);
      } elseif ($ext == "gif") {
          imagegif($img, $output);
      } else {
          error_log('Warning: Unsupported file extension', 3);
      }
        return imagedestroy($img);
    }
} else {
    add_action('admin_init', function () {
        add_action('admin_notices', function () {
            ?>
      <div class="notice notice-ewarning is-dismissible">
          <p><?php _e('Warning: GD library not installed. Bluring of uploaded images will fail.', 'wp-copyright'); ?></p>
      </div>
      <?php

        });
    });
}

class ZBWPCSettingsPage
{
    /**
     * Holds the values to be used in the fields callbacks
     */
    private $options;

    /**
     * Start up
     */
    public function __construct()
    {
        add_action( 'admin_menu', array( $this, 'add_plugin_page' ) );
        add_action( 'admin_init', array( $this, 'page_init' ) );
    }

    /**
     * Add options page
     */
    public function add_plugin_page()
    {
        // This page will be under "Settings"
        add_options_page(
            'Settings Admin',
            'WP Copyright',
            'manage_options',
            'zb-wp-copyright-admin',
            array( $this, 'create_admin_page' )
        );
    }

    /**
     * Options page callback
     */
    public function create_admin_page()
    {
        // Set class property
        $this->options = get_option( 'zb_wp_copyright_options' );
        ?>
        <div class="wrap">
            <h1>WP Copyright Settings</h1>
            <form method="post" action="options.php">
            <?php
                // This prints out all hidden setting fields
                settings_fields( 'zb_wp_copyright_option_group' );
                do_settings_sections( 'zb-wp-copyright-admin' );
                submit_button();
            ?>
            </form>
        </div>
        <?php
    }

    /**
     * Register and add settings
     */
    public function page_init()
    {
        register_setting(
            'zb_wp_copyright_option_group', // Option group
            'zb_wp_copyright_options', // Option name
            array( $this, 'sanitize' ) // Sanitize
        );

        add_settings_section(
            'keys_section', // ID
            'Keys', // Title
            array( $this, 'print_keys_section_info' ), // Callback
            'zb-wp-copyright-admin' // Page
        );

        add_settings_field(
            'zb_wpc_copyright_meta_key', // ID
            'Copyright Meta Key', // Title
            array( $this, 'copyright_meta_key_callback' ), // Callback
            'zb-wp-copyright-admin', // Page
            'keys_section' // Section
        );

        add_settings_field(
            'zb_wpc_locked_permissions', // ID
            'Locked Permissions', // Title
            array( $this, 'locked_permissions_callback' ), // Callback
            'zb-wp-copyright-admin', // Page
            'permissions_section' // Section
        );

        add_settings_section(
            'permissions_section', // ID
            'Permissions', // Title
            array( $this, 'print_permissions_section_info' ), // Callback
            'zb-wp-copyright-admin' // Page
        );

        add_settings_field(
            'zb_wpc_released_permissions', // ID
            'Released Permissions', // Title
            array( $this, 'released_permissions_callback' ), // Callback
            'zb-wp-copyright-admin', // Page
            'permissions_section' // Section
        );

        add_settings_field(
            'zb_wpc_exif_copyright', // ID
            'Exif Copyright Key', // Title
            array( $this, 'exif_copyright_callback' ), // Callback
            'zb-wp-copyright-admin', // Page
            'keys_section' // Section
        );

    }

    /**
     * Sanitize each setting field as needed
     *
     * @param array $input Contains all settings fields as array keys
     */
    public function sanitize( $input )
    {
        $new_input = array();

        $is_octal = function ($x) {
            return decoct(octdec($x)) == $x;
        };

        if( isset( $input['zb_wpc_copyright_meta_key'] ) )
            $new_input['zb_wpc_copyright_meta_key'] = sanitize_text_field( $input['zb_wpc_copyright_meta_key'] );

        if( !empty( $input['zb_wpc_locked_permissions'] ) and $is_octal($input['zb_wpc_locked_permissions']) )
            $new_input['zb_wpc_locked_permissions'] = sanitize_text_field( $input['zb_wpc_locked_permissions'] );

        if( !empty( $input['zb_wpc_released_permissions'] ) and $is_octal($input['zb_wpc_released_permissions']) )
            $new_input['zb_wpc_released_permissions'] = sanitize_text_field( $input['zb_wpc_released_permissions'] );

        if( isset( $input['zb_wpc_exif_copyright'] ) )
            $new_input['zb_wpc_exif_copyright'] = sanitize_text_field( $input['zb_wpc_exif_copyright'] );

        return $new_input;
    }

    /**
     * Print the Section text
     */
    public function print_keys_section_info()
    {
        print 'The keys that will be used for reading copyright information from Exif data and for storing copyright information as post meta';
    }

    /**
     * Print the Section text
     */
    public function print_permissions_section_info()
    {
        print 'The file permissions for released and locked files. Values must be formatted as octal numbers.';
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function copyright_meta_key_callback()
    {
        printf(
            '<input type="text" id="meta_key" name="zb_wp_copyright_options[zb_wpc_copyright_meta_key]" value="%s" />&nbsp;<i>e.g. "copyright"</i>',
            isset( $this->options['zb_wpc_copyright_meta_key'] ) ? esc_attr( $this->options['zb_wpc_copyright_meta_key']) : ''
        );
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function locked_permissions_callback()
    {
        printf(
            '<input type="text" id="locked_permissions" name="zb_wp_copyright_options[zb_wpc_locked_permissions]" value="%s" />&nbsp;<i>e.g. '.ZB_WPC_DEFAULT_LOCKED_PERMISSIONS.'</i>',
            isset( $this->options['zb_wpc_locked_permissions'] ) ? esc_attr( $this->options['zb_wpc_locked_permissions']) : ''
        );
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function released_permissions_callback()
    {
        printf(
            '<input type="text" id="released_permissions" name="zb_wp_copyright_options[zb_wpc_released_permissions]" value="%s" />&nbsp;<i>e.g. '.ZB_WPC_DEFAULT_RELEASED_PERMISSIONS.'</i>',
            isset( $this->options['zb_wpc_released_permissions'] ) ? esc_attr( $this->options['zb_wpc_released_permissions']) : ''
        );


    }

    /**
     * Get the settings option array and print one of its values
     */
    public function exif_copyright_callback()
    {
        printf(
            '<input type="text" id="exif_copyright" name="zb_wp_copyright_options[zb_wpc_exif_copyright]" value="%s" />&nbsp;<i>e.g. "copyright"</i>',
            isset( $this->options['zb_wpc_exif_copyright'] ) ? esc_attr( $this->options['zb_wpc_exif_copyright']) : ''
        );
    }

}

if( is_admin() )
    $wp_copyright_settings_page = new ZBWPCSettingsPage();
