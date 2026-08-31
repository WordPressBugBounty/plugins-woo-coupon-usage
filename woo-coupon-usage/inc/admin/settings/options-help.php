<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Admin settings UI: echoed values are internal pre-escaped helper markup and static strings; verified safe in manual audit.

function wcusage_field_cb_help( $args )
{
    $options = wcusage_get_options();
    ?>

<style>
  .ca-support, .ca-support * { box-sizing: border-box; }
  .ca-support {
    width: 100%;
    margin: 6px 0 40px;
    color: #1f2937;
    font-size: 14px;
    line-height: 1.5;
  }

  /* Hero header */
  .ca-support__hero {
    position: relative;
    overflow: hidden;
    background: linear-gradient(135deg, #2271b1 0%, #0f4c81 100%);
    border-radius: 16px;
    padding: 34px 38px;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 24px;
    flex-wrap: wrap;
  }
  .ca-support__hero::after {
    content: "";
    position: absolute;
    right: -50px; top: -70px;
    width: 220px; height: 220px;
    background: rgba(255, 255, 255, 0.08);
    border-radius: 50%;
  }
  .ca-support__hero::before {
    content: "";
    position: absolute;
    right: 90px; bottom: -110px;
    width: 180px; height: 180px;
    background: rgba(255, 255, 255, 0.06);
    border-radius: 50%;
  }
  .ca-support__hero-text { position: relative; z-index: 1; }
  .ca-support__hero h1 {
    color: #fff !important;
    font-size: 26px;
    font-weight: 700;
    margin: 0 0 6px;
    padding: 0;
    line-height: 1.2;
  }
  .ca-support__hero p {
    margin: 0;
    font-size: 15px;
    max-width: 560px;
    color: rgba(255, 255, 255, 0.88);
  }

  /* Buttons */
  .ca-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 11px 20px;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 600;
    text-decoration: none;
    line-height: 1;
    border: 1px solid transparent;
    cursor: pointer;
    transition: transform .15s ease, box-shadow .15s ease, background .15s ease;
    white-space: nowrap;
  }
  .ca-btn:hover { transform: translateY(-1px); }
  .ca-btn:focus { outline: none; box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.5); }
  .ca-btn--light {
    position: relative;
    z-index: 1;
    background: #fff;
    color: #0f4c81;
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.14);
  }
  .ca-btn--light:hover { box-shadow: 0 6px 20px rgba(0, 0, 0, 0.2); }
  .ca-btn .dashicons { font-size: 17px; width: 17px; height: 17px; }

  /* Card grid */
  .ca-support__cards {
    display: grid;
    grid-template-columns: 1.35fr 1fr;
    gap: 22px;
    margin-top: 24px;
  }
  .ca-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 16px;
    padding: 26px;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
  }
  .ca-card__icon {
    width: 46px; height: 46px;
    border-radius: 12px;
    display: inline-flex; align-items: center; justify-content: center;
    background: #eef5fb; color: #2271b1;
    margin-bottom: 16px;
  }
  .ca-card__icon .dashicons { font-size: 24px; width: 24px; height: 24px; }
  .ca-card h2 {
    font-size: 18px;
    font-weight: 700;
    margin: 0 0 6px;
    padding: 0;
    color: #111827;
  }
  .ca-card__sub {
    margin: 0 0 20px;
    margin-bottom: 20px !important;
    color: #6b7280;
    font-size: 14px;
  }

  /* Search */
  .ca-search { position: relative; max-width: 480px; }
  .ca-search > .dashicons-search {
    position: absolute;
    left: 13px; top: 50%;
    transform: translateY(-50%);
    color: #9ca3af;
    font-size: 18px; width: 18px; height: 18px;
    pointer-events: none;
  }
  .ca-support .docs-search-input {
    width: 100%;
    padding: 12px 14px 12px 40px;
    border: 1px solid #d1d5db;
    border-radius: 10px;
    font-size: 14px;
    background: #fff;
    box-shadow: none;
    transition: border-color .15s, box-shadow .15s;
  }
  .ca-support .docs-search-input:focus {
    border-color: #2271b1;
    box-shadow: 0 0 0 3px rgba(34, 113, 177, 0.15);
    outline: none;
  }
  #docs-search-results { margin-top: 18px; }
  #docs-search-results:empty { margin-top: 0; }
  .ca-card__link {
    display: inline-flex; align-items: center; gap: 6px;
    margin-top: 16px;
    color: #2271b1; font-weight: 600; text-decoration: none; font-size: 13px;
  }
  .ca-card__link:hover { text-decoration: underline; }
  .ca-card__link .dashicons { font-size: 15px; width: 15px; height: 15px; }

  /* Quick links */
  .ca-links { list-style: none; margin: 0; padding: 0; }
  .ca-links li { margin: 0; }
  .ca-links li + li { margin-top: 10px; }
  .ca-links a {
    display: flex; align-items: center; gap: 12px;
    padding: 12px 14px;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    text-decoration: none;
    color: #1f2937;
    font-weight: 500;
    transition: border-color .15s, background .15s, transform .15s;
  }
  .ca-links a:hover { border-color: #2271b1; background: #f8fbfe; transform: translateX(2px); }
  .ca-links a > .dashicons:first-child { color: #2271b1; flex: none; }
  .ca-links__arrow { margin-left: auto; color: #9ca3af; }

  /* Videos */
  .ca-videos { margin-top: 40px; }
  .ca-videos__head { margin-bottom: 20px; }
  .ca-videos__head h2 {
    font-size: 20px; font-weight: 700; margin: 0 0 4px; padding: 0; color: #111827;
  }
  .ca-videos__head p { margin: 0; color: #6b7280; }
  .ca-video-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
    gap: 22px;
  }
  .ca-video-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
    transition: box-shadow .2s, transform .2s;
  }
  .ca-video-card:hover { box-shadow: 0 8px 24px rgba(0, 0, 0, 0.08); transform: translateY(-2px); }
  .ca-video-card__embed { line-height: 0; background: #000; }
  .ca-video-card__embed > div { max-width: none !important; }
  .ca-video-card__title {
    display: flex; align-items: center; gap: 8px;
    padding: 14px 18px;
    font-size: 15px; font-weight: 600; color: #111827;
  }
  .ca-badge {
    display: inline-block;
    font-size: 11px; font-weight: 700;
    padding: 2px 8px;
    border-radius: 20px;
    background: #fdecc8; color: #92600a;
    letter-spacing: .02em;
  }

  @media screen and (max-width: 960px) {
    .ca-support__cards { grid-template-columns: 1fr; }
  }
  @media screen and (max-width: 600px) {
    .ca-support__hero { padding: 26px; }
    .ca-support__hero h1 { font-size: 22px; }
    .ca-video-grid { grid-template-columns: 1fr; }
  }
</style>

<div class="ca-support">

  <!-- Hero header -->
  <div class="ca-support__hero">
    <div class="ca-support__hero-text">
      <h1><?php echo esc_html__( 'Support', 'woo-coupon-usage' ); ?></h1>
      <p><?php echo esc_html__( 'Need help or have a suggestion? Please reach out to our support team.', 'woo-coupon-usage' ); ?></p>
    </div>
    <a class="ca-btn ca-btn--light" href="https://wordpress.org/support/plugin/woo-coupon-usage/#new-topic-0" target="_blank" rel="noopener">
      <span class="dashicons dashicons-testimonial"></span>
      <?php echo esc_html__( 'Create a Support Ticket', 'woo-coupon-usage' ); ?>
    </a>
  </div>

  <!-- Support cards -->
  <div class="ca-support__cards">

    <!-- Documentation search -->
    <div class="ca-card">
      <span class="ca-card__icon"><span class="dashicons dashicons-search"></span></span>
      <h2><?php echo esc_html__( 'Search Documentation', 'woo-coupon-usage' ); ?></h2>
      <p class="ca-card__sub"><?php echo esc_html__( 'Find help with common questions, setup and features.', 'woo-coupon-usage' ); ?></p>

      <div class="ca-search">
        <input class="docs-search-input" type="text" autocomplete="off"
          placeholder="<?php echo esc_attr__( 'Search documentation...', 'woo-coupon-usage' ); ?>">
      </div>

      <div id="docs-search-results"></div>

      <a class="ca-card__link" href="https://couponaffiliates.com/docs/?utm_campaign=plugin&utm_source=dashboard-link&utm_medium=documentation" target="_blank" rel="noopener">
        <?php echo esc_html__( 'Browse all documentation', 'woo-coupon-usage' ); ?>
        <span class="dashicons dashicons-external"></span>
      </a>
    </div>

    <!-- Quick links -->
    <div class="ca-card">
      <span class="ca-card__icon"><span class="dashicons dashicons-sos"></span></span>
      <h2><?php echo esc_html__( 'Get Help', 'woo-coupon-usage' ); ?></h2>
      <p class="ca-card__sub"><?php echo esc_html__( 'Reach out or jump straight to the guides.', 'woo-coupon-usage' ); ?></p>
      <ul class="ca-links">
        <li>
          <a href="https://wordpress.org/support/plugin/woo-coupon-usage/#new-topic-0" target="_blank" rel="noopener">
            <span class="dashicons dashicons-testimonial"></span>
            <span><?php echo esc_html__( 'Open a support ticket', 'woo-coupon-usage' ); ?></span>
            <span class="dashicons dashicons-arrow-right-alt2 ca-links__arrow"></span>
          </a>
        </li>
        <li>
          <a href="https://couponaffiliates.com/docs/setup-guide-free/?utm_campaign=plugin&utm_source=dashboard-link&utm_medium=documentation" target="_blank" rel="noopener">
            <span class="dashicons dashicons-book"></span>
            <span><?php echo esc_html__( 'Setup guide &ndash; get started', 'woo-coupon-usage' ); ?></span>
            <span class="dashicons dashicons-arrow-right-alt2 ca-links__arrow"></span>
          </a>
        </li>
        <li>
          <a href="https://couponaffiliates.com/docs/?utm_campaign=plugin&utm_source=dashboard-link&utm_medium=documentation" target="_blank" rel="noopener">
            <span class="dashicons dashicons-media-document"></span>
            <span><?php echo esc_html__( 'Browse all documentation', 'woo-coupon-usage' ); ?></span>
            <span class="dashicons dashicons-arrow-right-alt2 ca-links__arrow"></span>
          </a>
        </li>
      </ul>
    </div>

  </div>

  <!-- Video guides -->
  <div class="ca-videos">
    <div class="ca-videos__head">
      <h2><?php echo esc_html__( 'Video Guides', 'woo-coupon-usage' ); ?></h2>
      <p><?php echo esc_html__( 'Watch step-by-step walkthroughs of the main features.', 'woo-coupon-usage' ); ?></p>
    </div>
    <div class="ca-video-grid">
      <?php
      $wcusage_help_videos = array(
          array( 'id' => '709270929', 'title' => __( 'Setup Guide', 'woo-coupon-usage' ) ),
          array( 'id' => '713487822', 'title' => __( 'Registration Guide', 'woo-coupon-usage' ) ),
          array( 'id' => '845540018', 'title' => __( 'Affiliate Dashboard Demo', 'woo-coupon-usage' ) ),
          array( 'id' => '837140385', 'title' => __( 'Commission Payouts', 'woo-coupon-usage' ), 'pro' => true ),
          array( 'id' => '837197420', 'title' => __( 'PayPal Payouts', 'woo-coupon-usage' ), 'pro' => true ),
          array( 'id' => '837181248', 'title' => __( 'Stripe Payouts', 'woo-coupon-usage' ), 'pro' => true ),
          array( 'id' => '1106083661', 'title' => __( 'Wise Payouts', 'woo-coupon-usage' ), 'pro' => true ),
          array( 'id' => '877865633', 'title' => __( 'Performance Bonuses', 'woo-coupon-usage' ), 'pro' => true ),
          array( 'id' => '851299976', 'title' => __( 'Dynamic Creatives', 'woo-coupon-usage' ), 'pro' => true ),
          array( 'id' => '1066553896', 'title' => __( 'Affiliate Portal', 'woo-coupon-usage' ), 'pro' => true ),
          array( 'id' => '1098315791', 'title' => __( 'Floating Affiliate Widget', 'woo-coupon-usage' ), 'pro' => true ),
          array( 'id' => '842881764', 'title' => __( 'Bulk Edit Coupon Settings', 'woo-coupon-usage' ) ),
          array( 'id' => '842880473', 'title' => __( 'Bulk Edit Product Settings', 'woo-coupon-usage' ) ),
          array( 'id' => '842879742', 'title' => __( 'Bulk Assign Coupons to Orders', 'woo-coupon-usage' ) ),
          array( 'id' => '842878926', 'title' => __( 'Bulk Create Affiliate Coupons', 'woo-coupon-usage' ) ),
          array( 'id' => '842877907', 'title' => __( 'Import / Export Database Tables', 'woo-coupon-usage' ) ),
          array( 'id' => '706140611', 'title' => __( 'Multi-Level Affiliates Dashboard', 'woo-coupon-usage' ), 'pro' => true ),
          array( 'id' => '706471045', 'title' => __( 'MLA – Edit Parents', 'woo-coupon-usage' ), 'pro' => true ),
          array( 'id' => '706474905', 'title' => __( 'MLA – Edit Commission', 'woo-coupon-usage' ), 'pro' => true ),
      );
      foreach ( $wcusage_help_videos as $wcusage_video ) {
          $wcusage_video_embed = 'https://player.vimeo.com/video/' . $wcusage_video['id'] . '?badge=0&autopause=0&player_id=0&app_id=58479/embed';
          ?>
          <div class="ca-video-card">
            <div class="ca-video-card__embed"><?php echo wcusage_admin_vimeo_embed( $wcusage_video_embed ); ?></div>
            <div class="ca-video-card__title">
              <?php echo esc_html( $wcusage_video['title'] ); ?>
              <?php if ( ! empty( $wcusage_video['pro'] ) ) { echo '<span class="ca-badge">PRO</span>'; } ?>
            </div>
          </div>
          <?php
      }
      ?>
    </div>
  </div>

</div>

 <?php
}

// Enqueue scripts and localize AJAX data
add_action('admin_enqueue_scripts', 'couponaffiliates_enqueue_admin_scripts');
function couponaffiliates_enqueue_admin_scripts($hook) {

  wp_enqueue_script('jquery');
  
  // Add custom CSS
  $css = "
      .docs-result-box {
          background: #fff;
          border: 1px solid #e5e7eb;
          border-radius: 10px;
          padding: 15px;
          margin-bottom: 15px;
          box-shadow: 0 1px 3px rgba(0,0,0,0.04);
          transition: transform 0.2s, box-shadow 0.2s;
      }
      .docs-result-box:hover {
          transform: translateY(-2px);
          box-shadow: 0 4px 12px rgba(0,0,0,0.08);
      }
      .docs-result-box h4 {
          margin: 0 0 10px 0;
          color: #2271b1;
      }
      .docs-result-box p {
          margin: 0;
          color: #555;
          font-size: 14px;
      }
      .docs-result-box a {
          text-decoration: none;
      }
  ";
  wp_add_inline_style('wp-admin', $css);

  // Inline JavaScript
  $nonce = wp_create_nonce('couponaffiliates_docs_search');
  $ajax_url = admin_url('admin-ajax.php');
  $script = "
      jQuery(document).ready(function($) {
          var searchTimeout;
          $('.docs-search-input').on('keyup', function() {
              clearTimeout(searchTimeout);
              var query = $(this).val();
              if (query.length < 2) {
                  $('#docs-search-results').html('');
                  return;
              }
              $('#docs-search-results').html('<p>" . esc_js( __( 'Loading...', 'woo-coupon-usage' ) ) . "</p>');
              searchTimeout = setTimeout(function() {
                  $.ajax({
                      url: '{$ajax_url}',
                      type: 'POST',
                      data: {
                          action: 'couponaffiliates_search_docs',
                          nonce: '{$nonce}',
                          query: query
                      },
                      success: function(response) {
                          if (response.success) {
                              $('#docs-search-results').html(response.data.html);
                          } else {
                              $('#docs-search-results').html('<p>" . esc_js( __( 'No results found.', 'woo-coupon-usage' ) ) . "</p>');
                          }
                      },
                      error: function() {
                          $('#docs-search-results').html('<p>" . esc_js( __( 'Error fetching docs. Please try again.', 'woo-coupon-usage' ) ) . "</p>');
                      }
                  });
              }, 300);
          });
      });
  ";
  wp_add_inline_script('jquery', $script);
}

// AJAX handler to fetch and return docs
add_action('wp_ajax_couponaffiliates_search_docs', 'couponaffiliates_search_docs_callback');
function couponaffiliates_search_docs_callback() {
  check_ajax_referer('couponaffiliates_docs_search', 'nonce');

  if ( ! current_user_can( 'manage_options' ) ) {
      wp_send_json_error(['message' => __( 'Permission denied.', 'woo-coupon-usage' )]);
  }

  $query = isset($_POST['query']) ? sanitize_text_field( wp_unslash( $_POST['query'] ) ) : '';

  // Serve from cache when available; otherwise fetch once and cache for an hour.
  $cache_key = 'couponaffiliates_docs_' . md5( $query );
  $docs = get_transient( $cache_key );

  if ( false === $docs ) {
      $response = wp_remote_get(
          add_query_arg(
              [
                  'search' => $query,
                  'per_page' => 10,
              ],
              'https://couponaffiliates.com/wp-json/wp/v2/docs'
          ),
          ['timeout' => 15]
      );

      if (is_wp_error($response)) {
          wp_send_json_error(['message' => __( 'Failed to fetch docs.', 'woo-coupon-usage' )]);
      }

      $docs = json_decode(wp_remote_retrieve_body($response), true);

      set_transient( $cache_key, $docs, HOUR_IN_SECONDS );
  }

  if (empty($docs)) {
      wp_send_json_success(['html' => '<p>' . esc_html__( 'No results found.', 'woo-coupon-usage' ) . '</p>']);
  }

  // Build fancy boxed HTML output
  $html = '';
  foreach ($docs as $doc) {
      $title = esc_html( isset($doc['title']['rendered']) ? $doc['title']['rendered'] : '' );
      $link = esc_url( isset($doc['link']) ? $doc['link'] : '' );
      $excerpt = esc_html( wp_trim_words( wp_strip_all_tags( isset($doc['excerpt']['rendered']) ? $doc['excerpt']['rendered'] : '' ), 20, '...' ) );
      $html .= "
          <div class='docs-result-box'>
              <a href='$link' target='_blank'>
                  <h4>$title</h4>
                  <p>$excerpt</p>
              </a>
          </div>
      ";
  }

  wp_send_json_success(['html' => $html]);
}