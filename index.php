<?php
/*
Plugin Name: Vulcan Video Search
Author: Joseph Carrington
Author URI: http://www.josephcarrington.com
Description: A WordPress plugin to search videos imported from Vulcan's DOS formatted inventory sheets
Version: 0.2
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
    location tinytext,
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
    <h2>Plugin Options</h2>
    <form method="POST" action="options.php">
      <?php settings_fields('vulcan_video_settings'); ?>
      <?php $options = get_option('vulcan_video_settings'); ?>
      <?php
        $categories = isset($options['categories']) ? $options['categories'] : '';
        $stores = isset($options['stores']) ? $options['stores'] : '';
        $postsPerPage = isset($options['postsPerPage']) ? $options['postsPerPage'] : 25;
        $ignored = isset($options['ignored']) ? $options['ignored'] : '';

      ?>
      <table class="form-table">
        <tbody>
            <th scope="row">
              <label for="categories">Categories</label>
              <br />
              <code>Human Readable Name:Category Code</code>
            </th>
            <td>
              <textarea cols="50" rows="10" name="vulcan_video_settings[categories]" id="categories"><?php echo $categories; ?></textarea>
            </td>
          </tr>
          <tr>
            <th scope="row">
              <label for="stores">Stores</label>
              <br />
              <code>Human Readable Name:Store Code</code>
            </th>
            <td>
              <textarea cols="50" rows="3" name="vulcan_video_settings[stores]" id="stores"><?php echo $stores; ?></textarea>
            </td>
          </tr>
          <tr>
            <th scope="row">
              <label for="postsPerPage">Results Per Page</label>
            </th>
            <td>
              <input type="number" name="vulcan_video_settings[postsPerPage]" id="postsPerPage" value="<?php echo $postsPerPage; ?>" step="1" min="1" max="100" />
            </td>
          <tr>
          <tr>
            <th scope="row">
              <label for="ignored">Ignored Categories</label>
              <p>List category codes to ignore on the front-end, one category code per line</p>
            </th>
            <td>
              <textarea cols="50" rows="3" name="vulcan_video_settings[ignored]" id="ignored"><?php echo $ignored; ?></textarea>
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

add_action('wp_enqueue_scripts', function() {
  wp_enqueue_style('vulcan_video_search', plugin_dir_url(__FILE__) . 'vulcan_video_search.css');
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
    $csv = ','
    . trim($line->title)
    . ','
    . trim($line->format)
    . ','
    . trim($line->category)
    . ','
    . trim($line->location)
    . ','
    . trim($line->store)
    . "\n";
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

  $sql = "LOAD DATA LOCAL INFILE '" . plugin_dir_path(__FILE__) . $_POST['filename'] . "' INTO TABLE vulcan_videos FIELDS TERMINATED BY ',' ESCAPED BY'\\\'";
  $wpdb->query($sql);

  wp_die('Table cleared, and new records uploaded. All done!');
}

add_shortcode('vulcan_video_search', function($atts) {
  $html = '';
  if(isset($_GET['title']) || isset($_GET['category']) || isset($_GET['store'])) {
    global $wpdb;
    $titleQ = isset($_GET['title']) ? $_GET['title'] : NULL;
    $categoryQ = isset($_GET['category']) ? $_GET['category'] : NULL;
    $storeQ = isset($_GET['store']) ? $_GET['store'] : NULL;

    $pageQ = isset($_GET['vv_page']) ? $_GET['vv_page'] : 1;

    $settings = get_option('vulcan_video_settings');
    $postsPerPage = $settings['postsPerPage'];

    $query = "SELECT DISTINCT name, format, category, location, store FROM vulcan_videos WHERE 1=1";
    if($titleQ) {
      $titleQ = stripcslashes($titleQ);
      $safeTitle = $wpdb->esc_like($titleQ);
      $query .= $wpdb->prepare(" AND name LIKE %s", '%' . $safeTitle . '%');
    }
    if($categoryQ) {
      $query .= $wpdb->prepare(" AND category=%s", $categoryQ);
    }
    if($storeQ) {
      $query .= $wpdb->prepare(" AND store=%d", $storeQ);
    }

    $ignoredCategoriesText = isset($settings['ignored']) ? $settings['ignored'] : NULL;
    if($ignoredCategoriesText && $ignoredCategoriesText != '') {
      $query .= " AND category NOT IN (";
      $ignoredCats = explode("\n", $ignoredCategoriesText);
      error_log(print_r($ignoredCategoriesText, true));
      for($i = 0, $j = count($ignoredCats); $i < $j; $i ++) {
        $query .= "'" . trim($ignoredCats[$i]) . "'";
        if($i + 1 < $j) {
          $query .= ",";
        }
      }

      $query .= ")";
    }

    $query .= " ORDER BY name LIMIT " . ($postsPerPage + 1);
    if($pageQ) {
      $query .= $wpdb->prepare(" OFFSET %d", ($pageQ * $postsPerPage) - $postsPerPage);
    }

    $videos = $wpdb->get_results($query);
    if(count($videos) > 0) {
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
            $category = array_search($video->category, $categoryNamesToCodes);
            if($category == "Director's Wall") {
              $location = $video->location;
              $category .= " ($location)";
            }
            $html .= '<tr>';
              $html .= "<td>$video->name</td>";
              $html .= "<td>$video->format</td>";
              $html .= "<td>";
                $html .= $category;
              $html .= "</td>";
              $html .= "<td>";
                $html .= array_search($video->store, $storeNamesToCodes);
              $html .= "</td>";
            $html .= '</tr>';
          }
        $html .= '</tbody>';
      $html .= '</table>';
      // Still appears to be counting non distinct rows, so disabled for now
      // $html .= "<p>Showing " . ((($pageQ * $postsPerPage) - $postsPerPage) + 1) . " - " . ($pageQ * $postsPerPage) . " of $rows results</p>";
      $html .= '<div class="vv-pagination">';
      if($pageQ > 1) {
        $prevURL = $_SERVER['REDIRECT_URL'];
        $prevURL .= "?title=$titleQ&category=$categoryQ&store=$storeQ&vv_page=" . ($pageQ - 1);
        $html .= '<div class="nav-prev alignleft"><a href="' . $prevURL . '" title="Next videos">Previous videos</a></div>';
      }
      // If we have more records to show
      if(count($videos) > $postsPerPage) {
        $nextURL = $_SERVER['REDIRECT_URL'];
        $nextURL .= "?title=$titleQ&category=$categoryQ&store=$storeQ&vv_page=" . ($pageQ + 1);
        $html .= '<div class="nav-next alignright"><a href="' . $nextURL . '" title="Next videos">Next videos</a></div>';
      }
      $html .= '</div>';
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
          $html .= ' value="' . htmlspecialchars(stripcslashes($_GET['title'])) . '"';
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
