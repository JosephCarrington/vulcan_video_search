<?php
/*
Plugin Name: Vulcan Video Search
Author: Joseph Carrington
Author URI: http://www.josephcarrington.com
Description: A WordPress plugin to search videos imported from Vulcan's DOS formatted inventory sheets
*/
register_activation_hook(__FILE__, function() {
  // Get our globals and helpers
  global $wpdb;
  $charset_collate = $wpdb->get_charset_collate();
  require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

  $sql = "CREATE TABLE vulcan_videos (
    id mediumint(9) NOT NULL AUTO_INCREMENT,
    name tinytext NOT NULL,
    format tinytext NOT NULL,
    category tinytext,
    store int(2) NOT NULL,
    PRIMARY KEY  (id)
  ) $charset_collate";

  dbDelta($sql);
});

register_uninstall_hook(__FILE__, 'vulcan_video_uninstall');
function vulcan_video_uninstall() {
  // Get our globals and helpers
  global $wpdb;
  $charset_collate = $wpdb->get_charset_collate();
  require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

  $sql = "DROP TABLE IF EXISTS vulcan_videos";

  // dbDelta does not support dropping tables
  $wpdb->query($sql);
}

add_action('admin_menu', function() {
  if(is_admin() && current_user_can('administrator')) {
    add_menu_page(
      'Vulcan Video Search',
      'Video Search',
      'import',
      'vulcan_video_search',
      'vulcan_video_admin_menu',
      'dashicons-video-alt2'
    );
  };
});

add_action('admin_init', function() {
  register_setting('vulcan_video_settings', 'vulcan_video_settings');
});

function vulcan_video_admin_menu() {
  if(!current_user_can('import')) {
    return;
  }
  ?>
  <div class="wrap">
    <h1><?= esc_html(get_admin_page_title()); ?></h1>
    <h2>Upload new INFILES</h2>
    <p>Here you can upload INFILES to regenerate the video database</p>
    <form action="options.php" method="post" enctype="multipart/form-data">
      <table class="form-table">
        <tbody>
          <tr>
            <th scope="row">
              <label for="infiles">One Or More INFILES</label>
            </th>
            <td>
              <input type="file" name="infiles" id="infiles" multiple></input>
            </td>
          </tr>
          <tr>
            <th scope="row">Parsing Info</th>
            <td id="parsingInfo"></td>
            <ul id="filelist"></ul>
          </tr>
        </tbody>
      </table>
    </form>
    <br />
    <h2>Category Options</h2>
    <p>Here you can adjust the categories that a user can search by. Format is <code>Human Readable Name:Category Code</code>, one entry per line.</p>
    <form method="POST" action="options.php">
      <?php settings_fields('vulcan_video_settings'); ?>
      <?php $options = get_option('vulcan_video_settings'); ?>
      <?php
        $categories = isset($options['categories']) ? $options['categories'] : '';
        $stores = isset($options['stores']) ? $options['stores'] : '';
      ?>
      <table class="form-table">
        <tbody>
          <tr>
            <th scope="row">
              <label for="categories">Categories</label>
            </th>
            <td>
              <textarea cols="50" rows="10" name="vulcan_video_settings[categories]" id="categories"><?php echo $categories; ?></textarea>
            </td>
          </tr>
          <tr>
            <th scope="row">
              <label for="stores">Stores</label>
            </th>
            <td>
              <textarea cols="50" rows="3" name="vulcan_video_settings[stores]" id="stores"><?php echo $stores; ?></textarea>
            </td>
          </tr>
        </tbody>
      </table>
      <p class="submit">
        <input type="submit" class="button-primary" value="<?php _e('Save Changes'); ?>" />
      </p>
    </form>
  </div>
  <?php
}

add_action('admin_enqueue_scripts', function($hook) {
  if($hook == "toplevel_page_vulcan_video_search") {
    wp_register_script('vulcan_video_admin_js', plugin_dir_url(__FILE__) . 'scripts/admin_form.js', ['jquery', 'plupload']);
    wp_localize_script('vulcan_video_admin_js', 'serverVariables', array('pluginDirURL' => plugin_dir_url(__FILE__), 'ABSPATH' => ABSPATH));
    wp_enqueue_script('vulcan_video_admin_js');
  }
});

add_action( 'wp_ajax_vulcan_video_upload', 'vulcan_video_upload' );
function vulcan_video_upload() {
  if(!current_user_can('import')) {
    return;
  }
  $data = json_decode(file_get_contents('php://input'));
  $today = getdate();
  $filename = $today['mon'] . '-' . $today['mday'] . '-' . $today['year'] . '-videos.csv';
  $file = fopen(plugin_dir_path(__FILE__) . $filename, 'w');
  foreach($data as $line) {
    $line->title = addslashes($line->title);
    $line->title = (string)str_replace(',', '\,', $line->title);
    // fputcsv($file, (array)$line);
    $csv = ',' . $line->title . ',' . $line->format . ',' . $line->category . ',' . $line->store . "\n";
    fwrite($file, $csv);
  }
  fclose($file);
  wp_die($filename);
}

add_action('wp_ajax_vulcan_video_clear_and_insert', 'vulcan_video_clear_and_insert');
function vulcan_video_clear_and_insert() {
  if(!current_user_can('import')) {
    return;
  }

  global $wpdb;

  $sql = 'TRUNCATE TABLE vulcan_videos';
  $wpdb->query($sql);

  $sql = "LOAD DATA INFILE '" . plugin_dir_path(__FILE__) . $_POST['filename'] . "' INTO TABLE vulcan_videos FIELDS TERMINATED BY ',' ESCAPED BY'\\\'";
  $wpdb->query($sql);

  wp_die('Table cleared, and new records uploaded. All done!');
}

add_shortcode('vulcan_video_search', function($atts) {
  $html = '';
  if(isset($_GET['title']) || isset($_GET['category']) || isset($_GET['store'])) {
    global $wpdb;
    $title = isset($_GET['title']) ? $_GET['title'] : NULL;
    $category = isset($_GET['category']) ? $_GET['category'] : NULL;
    $store = isset($_GET['store']) ? $_GET['store'] : NULL;

    $query = "SELECT name, format, category, store FROM vulcan_videos WHERE 1=1";
    if($title) {
      $query .= $wpdb->prepare(" AND name LIKE %s", '%' . $wpdb->esc_like($title) . '%');
    }
    if($category) {
      $query .= $wpdb->prepare(" AND category=%s", $category);
    }
    if($store) {
      $query .= $wpdb->prepare(" AND store=%d", $store);
    }
    $videos = $wpdb->get_results($query);
    if(count($videos) > 0) {
      $settings = get_option('vulcan_video_settings');
      $categoriesText = $settings['categories'];
      $categories = explode("\n", $categoriesText);
      $categoryNamesToCodes = [];
      foreach($categories as $category) {
        $category = explode(':', $category);
        $categoryNamesToCodes[trim($category[0])] = trim($category[1]);
      }

      $storesText = $settings['stores'];
      $stores = explode("\n", $storesText);
      $storeNamesToCodes = [];
      foreach($stores as $store) {
        $store = explode(':', $store);
        $storeNamesToCodes[trim($store[0])] = trim($store[1]);
      }

      $html .= '<table id="videos">';
        $html .= '<tbody>';
          foreach($videos as $video) {
            $html .= '<tr>';
              $html .= "<td>$video->name</td>";
              $html .= "<td>$video->format</td>";
              $html .= "<td>";
                $html .= array_search($video->category, $categoryNamesToCodes);
              $html .= "</td>";
              $html .= "<td>";
                $html .= array_search($video->store, $storeNamesToCodes);
              $html .= "</td>";
            $html .= '</tr>';
          }
        $html .= '</tbody>';
      $html .= '</table>';
    }
    else {
      $html .= "<p>No records found.</p>";
    }
  }
  $html .= "<form>";
    $html .= "<fieldset>";
      $html .= '<label for="title">Title</label>';
      $html .= '<input type="text" name="title" id="title"';
        if(isset($_GET['title'])) {
          $html .= ' value="' . $_GET['title'] . '"';
        }
      $html .= ' />';
      $html .= '<label for="category">Category</label>';
      $html .= '<select name="category" id="category">';
        $html .= '<option value="">Any Category</option>';
        $categoryText = get_option('vulcan_video_settings')['categories'];
        $categories = explode("\n", $categoryText);
        foreach($categories as $category) {
          $catInfo = explode(':', $category);
          $html .= '<option ';
          if(isset($_GET['category'])) {
            if($_GET['category'] == trim($catInfo[1])) {
              $html .= 'selected ';
            }
          }
          $html .= 'value="' . trim($catInfo[1]) . '">' . trim($catInfo[0]) . '</option>';
        }
      $html .= '</select>';
      $html .= '<label for="store">Store Location</label>';
      $html .= '<select name="store" id="store">';
        $html .= '<option value="">Any Location</option>';
        $storeText = get_option('vulcan_video_settings')['stores'];
        $stores = explode("\n", $storeText);
        foreach($stores as $store) {
          $storeInfo = explode(':', $store);
          $html .= '<option ';
          if(isset($_GET['store'])) {
            if($_GET['store'] == trim($storeInfo[1])) {
              $html .= "selected ";
            }
          }
          $html .='value="' . trim($storeInfo[1]) . '">' . trim($storeInfo[0]) . '</option>';
        }
      $html .= '</select>';
    $html .= "</fieldset>";
    $html .= '<input type="submit" value="Search" />';
  $html .= "</form>";
  return $html;
});
