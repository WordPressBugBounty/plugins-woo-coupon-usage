<?php
/**
 * Email building blocks.
 *
 * These render the pieces the affiliate reports are made of - stat cards, data
 * tables, callouts. They are deliberately boring HTML: nested tables, inline
 * styles, no floats, no flexbox, no media queries.
 *
 * The reports used to lay their statistics out with `width:33%; float:left`
 * divs and push the supporting CSS through the global woocommerce_email_styles
 * filter. Floats are ignored by Outlook's rendering engine, so that grid
 * collapsed into a single stacked column for a large share of recipients, and
 * the filter applied the plugin's styling - including a header image override -
 * to every WooCommerce email the store sent, order confirmations included.
 *
 * Everything here is inlined at the element instead, which renders the same
 * everywhere and cannot leak into another email.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The colour and type tokens every component draws from.
 *
 * The accent defaults to the affiliate dashboard's button colour so reports look
 * like the rest of the store without another setting having to be filled in.
 */
if ( ! function_exists( 'wcusage_email_tokens' ) ) {
  function wcusage_email_tokens( $overrides = array() ) {

    $overrides = (array) $overrides;

    // The accent is resolved before the rest of the palette, because the soft
    // and mid tints are mixed FROM it. Merging an override in afterwards would
    // leave those two derived colours belonging to the previous accent.
    // An empty override falls through to the settings rather than blanking it.
    $accent = '';
    if ( ! empty( $overrides['accent'] ) ) {
      $accent = $overrides['accent'];
    }
    if ( ! $accent ) {
      $accent = wcusage_get_setting_value( 'wcusage_field_reports_email_accent', '' );
    }
    if ( ! $accent ) {
      $accent = wcusage_get_setting_value( 'wcusage_field_color_button', '#005d75' );
    }

    unset( $overrides['accent'] );

    $tokens = array(
      'accent'      => $accent,
      'accent_soft' => wcusage_email_tint( $accent, 0.93 ),
      'accent_mid'  => wcusage_email_tint( $accent, 0.62 ),
      'on_accent'   => wcusage_get_setting_value( 'wcusage_field_color_button_font', '#ffffff' ),
      'ink'         => '#181e28',
      'body'        => '#404856',
      'muted'       => '#7b8494',
      'rule'        => '#e6e9ee',
      'panel'       => '#f8f9fb',
      'white'       => '#ffffff',
      'positive'    => '#15803d',
      'negative'    => '#b92a2a',
      'font'        => "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif",
      'radius'      => '8px',
    );

    return array_merge( $tokens, $overrides );
  }
}

/**
 * Mix a hex colour towards white, for soft panel fills.
 */
if ( ! function_exists( 'wcusage_email_tint' ) ) {
  function wcusage_email_tint( $hex, $amount ) {
    $hex = ltrim( trim( (string) $hex ), '#' );
    if ( strlen( $hex ) === 3 ) {
      $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if ( ! preg_match( '/^[0-9a-fA-F]{6}$/', $hex ) ) {
      return '#f8f9fb';
    }
    $amount = max( 0, min( 1, (float) $amount ) );
    $out    = '#';
    for ( $i = 0; $i < 3; $i++ ) {
      $channel = hexdec( substr( $hex, $i * 2, 2 ) );
      $mixed   = (int) round( $channel + ( 255 - $channel ) * $amount );
      $out    .= str_pad( dechex( $mixed ), 2, '0', STR_PAD_LEFT );
    }
    return $out;
  }
}

/**
 * Hidden preview text - the line mail clients show next to the subject.
 */
if ( ! function_exists( 'wcusage_email_preheader' ) ) {
  function wcusage_email_preheader( $text ) {
    if ( ! $text ) { return ''; }
    return '<div style="display:none;font-size:1px;color:#ffffff;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;">'
      . esc_html( $text )
      . str_repeat( '&#8203;&nbsp;', 60 )
      . '</div>';
  }
}

/**
 * Open and close a full-width presentation table. Every block sits in one so
 * that clients which ignore block-level margins still space things correctly.
 */
if ( ! function_exists( 'wcusage_email_block' ) ) {
  function wcusage_email_block( $content, $padding = '0 0 28px 0' ) {
    if ( trim( (string) $content ) === '' ) { return ''; }
    return '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="width:100%;border-collapse:collapse;">'
      . '<tr><td style="padding:' . esc_attr( $padding ) . ';">' . $content . '</td></tr></table>';
  }
}

/**
 * "+12.4%", or null when there is nothing meaningful to compare against.
 */
if ( ! function_exists( 'wcusage_email_delta_text' ) ) {
  function wcusage_email_delta_text( $delta ) {
    if ( $delta === null || $delta === '' || ! is_numeric( $delta ) ) {
      return null;
    }
    $delta = (float) $delta;
    $sign  = $delta > 0 ? '+' : ( $delta < 0 ? '-' : '' );
    return $sign . number_format( abs( $delta ), 1 ) . '%';
  }
}

/**
 * Masthead: the report title on the left, the period and coupon on the right,
 * over a rule. Gives the email the context the PDF header has - the coupon
 * code was not shown anywhere in the email before this.
 */
if ( ! function_exists( 'wcusage_email_masthead' ) ) {
  function wcusage_email_masthead( $title, $meta, $t ) {

    if ( ! $title && ! $meta ) { return ''; }

    $html = '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="width:100%;border-collapse:collapse;"><tr>'
      . '<td valign="bottom" style="padding:0 0 10px 0;border-bottom:2px solid ' . esc_attr( $t['ink'] ) . ';font-family:' . esc_attr( $t['font'] ) . ';font-size:11px;line-height:16px;letter-spacing:0.1em;text-transform:uppercase;font-weight:700;color:' . esc_attr( $t['ink'] ) . ';">'
      . esc_html( $title ) . '</td>';

    if ( $meta ) {
      $html .= '<td valign="bottom" align="right" style="padding:0 0 10px 12px;border-bottom:2px solid ' . esc_attr( $t['ink'] ) . ';font-family:' . esc_attr( $t['font'] ) . ';font-size:12px;line-height:16px;color:' . esc_attr( $t['muted'] ) . ';text-align:right;white-space:nowrap;">'
        . esc_html( $meta ) . '</td>';
    }

    $html .= '</tr></table>';

    return wcusage_email_block( $html, '0 0 22px 0' );
  }
}

/**
 * The headline block: period name, the figure that matters, and how it compares.
 *
 * A slim accent bar down the left edge instead of a tinted slab, so the block
 * reads as a heading rather than as one more card.
 */
if ( ! function_exists( 'wcusage_email_hero' ) ) {
  function wcusage_email_hero( $args, $t ) {

    $args = wp_parse_args( $args, array(
      'eyebrow' => '',
      'value'   => '',
      'label'   => '',
      'delta'   => null,
      'compare' => '',
    ) );

    $delta_text = wcusage_email_delta_text( $args['delta'] );
    $colour     = $t['muted'];
    if ( $delta_text !== null && (float) $args['delta'] > 0 ) { $colour = $t['positive']; }
    if ( $delta_text !== null && (float) $args['delta'] < 0 ) { $colour = $t['negative']; }

    $html = '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="width:100%;border-collapse:separate;background-color:' . esc_attr( $t['panel'] ) . ';border-radius:' . esc_attr( $t['radius'] ) . ';">'
      . '<tr>'
      . '<td width="5" bgcolor="' . esc_attr( $t['accent'] ) . '" style="width:5px;padding:0;background-color:' . esc_attr( $t['accent'] ) . ';border-radius:' . esc_attr( $t['radius'] ) . ' 0 0 ' . esc_attr( $t['radius'] ) . ';font-size:0;line-height:0;">&nbsp;</td>'
      . '<td style="padding:22px 24px 22px 22px;font-family:' . esc_attr( $t['font'] ) . ';">';

    if ( $args['eyebrow'] ) {
      $html .= '<div style="font-size:11px;letter-spacing:0.08em;text-transform:uppercase;font-weight:700;color:' . esc_attr( $t['accent'] ) . ';padding-bottom:10px;">'
        . esc_html( $args['eyebrow'] ) . '</div>';
    }

    if ( $args['label'] ) {
      $html .= '<div style="font-size:13px;color:' . esc_attr( $t['muted'] ) . ';padding-bottom:4px;">' . esc_html( $args['label'] ) . '</div>';
    }

    // Value on the left; the comparison on the right as ONE line, vertically
    // centred on the figure. Stacking the delta over its caption left the
    // caption hanging below the number's baseline, which read as misaligned.
    $html .= '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="width:100%;border-collapse:collapse;"><tr>'
      . '<td valign="middle" style="padding:0;vertical-align:middle;font-family:' . esc_attr( $t['font'] ) . ';font-size:36px;line-height:40px;font-weight:700;color:' . esc_attr( $t['ink'] ) . ';letter-spacing:-0.01em;">'
      . esc_html( $args['value'] ) . '</td>';

    if ( $delta_text !== null || $args['compare'] ) {
      $html .= '<td valign="middle" align="right" style="padding:0 0 0 16px;vertical-align:middle;font-family:' . esc_attr( $t['font'] ) . ';text-align:right;white-space:nowrap;line-height:22px;">';
      if ( $delta_text !== null ) {
        $html .= '<span style="font-size:18px;font-weight:700;color:' . esc_attr( $colour ) . ';vertical-align:middle;">' . esc_html( $delta_text ) . '</span>';
      }
      if ( $args['compare'] ) {
        $html .= ( $delta_text !== null ? '&nbsp;&nbsp;' : '' )
          . '<span style="font-size:13px;color:' . esc_attr( $t['muted'] ) . ';vertical-align:middle;">' . esc_html( $args['compare'] ) . '</span>';
      }
      $html .= '</td>';
    }

    $html .= '</tr></table>';

    $html .= '</td></tr></table>';

    return wcusage_email_block( $html );
  }
}

/**
 * A grid of stat cards.
 *
 * Two per row by default. Three would fit a desktop window comfortably but
 * leaves about 90px per card on a phone, which is not enough for a formatted
 * currency figure; two keeps every card readable at 320px without needing a
 * media query that half the clients would drop anyway.
 *
 * The comparison sits to the right of the figure rather than underneath it. A
 * card with nothing to compare against used to keep an empty line below the
 * number, which read as a layout bug on every card without a delta.
 */
if ( ! function_exists( 'wcusage_email_kpi_grid' ) ) {
  function wcusage_email_kpi_grid( $cards, $t, $args = array() ) {

    $cards = array_values( array_filter( (array) $cards ) );
    if ( empty( $cards ) ) { return ''; }

    $args = wp_parse_args( $args, array(
      'columns'     => 2,
      'align'       => 'left',
      'show_deltas' => true,
    ) );

    $columns    = max( 1, min( 3, (int) $args['columns'] ) );
    $cell_width = round( 100 / $columns, 4 );
    $align      = ( $args['align'] === 'center' ) ? 'center' : 'left';

    // table-layout:fixed makes the column widths binding. Without it a card
    // whose figure plus delta is wider than half the screen pushes its column
    // out and the row runs off the right edge of a phone.
    $html = '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="width:100%;border-collapse:collapse;table-layout:fixed;">';

    $rows = array_chunk( $cards, $columns );

    foreach ( $rows as $row ) {
      $html .= '<tr>';
      for ( $i = 0; $i < $columns; $i++ ) {

        $html .= '<td width="' . esc_attr( $cell_width ) . '%" valign="top" style="width:' . esc_attr( $cell_width ) . '%;padding:0 '
          . ( $i === $columns - 1 ? '0' : '5px' ) . ' 10px ' . ( $i === 0 ? '0' : '5px' ) . ';">';

        if ( isset( $row[ $i ] ) ) {

          $card = wp_parse_args( $row[ $i ], array(
            'label' => '', 'value' => '', 'delta' => null, 'note' => '', 'highlight' => false,
          ) );

          $bg     = $card['highlight'] ? $t['accent_soft'] : $t['white'];
          $border = $card['highlight'] ? $t['accent_mid'] : $t['rule'];
          $value  = $card['highlight'] ? $t['accent'] : $t['ink'];

          $delta_text = $args['show_deltas'] ? wcusage_email_delta_text( $card['delta'] ) : null;
          $colour     = $t['muted'];
          if ( $delta_text !== null && (float) $card['delta'] > 0 ) { $colour = $t['positive']; }
          if ( $delta_text !== null && (float) $card['delta'] < 0 ) { $colour = $t['negative']; }

          $html .= '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="width:100%;border-collapse:separate;background-color:' . esc_attr( $bg ) . ';border:1px solid ' . esc_attr( $border ) . ';border-radius:' . esc_attr( $t['radius'] ) . ';">'
            . '<tr><td style="padding:14px 16px 14px 16px;font-family:' . esc_attr( $t['font'] ) . ';text-align:' . esc_attr( $align ) . ';">'
            . '<div style="font-size:10px;line-height:14px;letter-spacing:0.07em;text-transform:uppercase;font-weight:700;color:' . esc_attr( $t['muted'] ) . ';padding-bottom:7px;">'
            . esc_html( $card['label'] ) . '</div>';

          // Figure and comparison on one line. When centred there is no "right"
          // to put the delta in, so it follows the figure inline instead.
          if ( $align === 'center' ) {
            $html .= '<div style="font-size:22px;line-height:26px;font-weight:700;color:' . esc_attr( $value ) . ';">' . esc_html( $card['value'] );
            if ( $delta_text !== null ) {
              $html .= ' <span style="font-size:12px;font-weight:700;color:' . esc_attr( $colour ) . ';">' . esc_html( $delta_text ) . '</span>';
            }
            if ( $card['note'] ) {
              $html .= ' <span style="font-size:11px;font-weight:400;color:' . esc_attr( $t['muted'] ) . ';">' . esc_html( $card['note'] ) . '</span>';
            }
            $html .= '</div>';
          } else {
            $html .= '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="width:100%;border-collapse:collapse;"><tr>'
              . '<td valign="bottom" style="padding:0;vertical-align:bottom;font-family:' . esc_attr( $t['font'] ) . ';font-size:20px;line-height:24px;font-weight:700;color:' . esc_attr( $value ) . ';">'
              . esc_html( $card['value'] ) . '</td>';
            if ( $delta_text !== null || $card['note'] ) {
              $html .= '<td valign="bottom" align="right" style="padding:0 0 3px 8px;vertical-align:bottom;font-family:' . esc_attr( $t['font'] ) . ';text-align:right;white-space:nowrap;font-size:12px;line-height:16px;">';
              if ( $delta_text !== null ) {
                $html .= '<span style="font-weight:700;color:' . esc_attr( $colour ) . ';">' . esc_html( $delta_text ) . '</span>';
              }
              if ( $card['note'] ) {
                $html .= ( $delta_text !== null ? ' ' : '' ) . '<span style="color:' . esc_attr( $t['muted'] ) . ';">' . esc_html( $card['note'] ) . '</span>';
              }
              $html .= '</td>';
            }
            $html .= '</tr></table>';
          }

          $html .= '</td></tr></table>';
        } else {
          $html .= '&nbsp;';
        }

        $html .= '</td>';
      }
      $html .= '</tr>';
    }

    $html .= '</table>';

    return wcusage_email_block( $html, '0 0 18px 0' );
  }
}

/**
 * A data table.
 *
 * $columns: array( array( 'label' => string, 'align' => 'left'|'right', 'width' => '40%',
 *                         'bar' => bool, 'bold' => bool, 'muted' => bool ) )
 * $rows:    array( array( 'cells' => array(string), 'bar' => float 0-1 ) )
 *
 * Hairlines only - no header band, no zebra fill - so several tables in a row
 * still read as one quiet list rather than a stack of boxes.
 */
if ( ! function_exists( 'wcusage_email_table' ) ) {
  function wcusage_email_table( $columns, $rows, $t, $args = array() ) {

    if ( empty( $columns ) || empty( $rows ) ) { return ''; }

    $html = '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="width:100%;border-collapse:collapse;">';

    $html .= '<tr>';
    foreach ( $columns as $col ) {
      $align = isset( $col['align'] ) ? $col['align'] : 'left';
      $width = isset( $col['width'] ) ? ' width="' . esc_attr( $col['width'] ) . '"' : '';
      $html .= '<td' . $width . ' style="' . ( isset( $col['width'] ) ? 'width:' . esc_attr( $col['width'] ) . ';' : '' )
        . 'padding:0 0 8px 0;border-bottom:2px solid ' . esc_attr( $t['rule'] ) . ';font-family:' . esc_attr( $t['font'] )
        . ';font-size:10px;letter-spacing:0.07em;text-transform:uppercase;font-weight:700;color:' . esc_attr( $t['muted'] )
        . ';text-align:' . esc_attr( $align ) . ';">' . esc_html( $col['label'] ) . '</td>';
    }
    $html .= '</tr>';

    $count = count( $rows );

    foreach ( $rows as $index => $row ) {

      $cells   = isset( $row['cells'] ) ? $row['cells'] : $row;
      $bar_pct = isset( $row['bar'] ) ? max( 0, min( 1, (float) $row['bar'] ) ) : null;
      $last    = ( $index === $count - 1 );

      $html .= '<tr>';
      foreach ( $columns as $i => $col ) {

        $align  = isset( $col['align'] ) ? $col['align'] : 'left';
        $value  = isset( $cells[ $i ] ) ? (string) $cells[ $i ] : '';
        $weight = ! empty( $col['bold'] ) ? '700' : '400';
        $colour = ! empty( $col['muted'] ) ? $t['muted'] : $t['body'];

        // Padding is always the full shorthand: WooCommerce's email CSS puts
        // 12px on every nested cell, and a lone padding-left would only win
        // on the left.
        $html .= '<td valign="top" style="padding:11px 0 11px ' . ( $i === 0 ? '0' : '12px' ) . ';vertical-align:top;' . ( $last ? '' : 'border-bottom:1px solid ' . esc_attr( $t['rule'] ) . ';' )
          . 'font-family:' . esc_attr( $t['font'] ) . ';font-size:13px;line-height:18px;font-weight:' . $weight . ';color:' . esc_attr( $colour )
          . ';text-align:' . esc_attr( $align ) . ';">' . esc_html( $value );

        if ( ! empty( $col['bar'] ) && $bar_pct !== null ) {
          // A filled portion over a faint full-width track, so the bar reads as
          // a share of the largest row rather than as a stray underline.
          $filled = max( 2, min( 100, (int) round( $bar_pct * 100 ) ) );
          $rest   = 100 - $filled;
          $html .= '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="width:100%;border-collapse:collapse;margin-top:7px;">'
            . '<tr><td width="' . esc_attr( $filled ) . '%" height="4" bgcolor="' . esc_attr( $t['accent'] ) . '" style="width:' . esc_attr( $filled ) . '%;height:4px;padding:0;background-color:' . esc_attr( $t['accent'] ) . ';font-size:0;line-height:0;border-radius:2px 0 0 2px;">&nbsp;</td>'
            . ( $rest > 0 ? '<td width="' . esc_attr( $rest ) . '%" height="4" bgcolor="' . esc_attr( $t['rule'] ) . '" style="width:' . esc_attr( $rest ) . '%;height:4px;padding:0;background-color:' . esc_attr( $t['rule'] ) . ';font-size:0;line-height:0;border-radius:0 2px 2px 0;">&nbsp;</td>' : '' )
            . '</tr></table>';
        }

        $html .= '</td>';
      }
      $html .= '</tr>';
    }

    $html .= '</table>';

    return wcusage_email_block( $html, '0 0 24px 0' );
  }
}

/**
 * Section heading: title on the left, optional context on the right, a rule
 * beneath both.
 */
if ( ! function_exists( 'wcusage_email_heading' ) ) {
  function wcusage_email_heading( $title, $t, $subtitle = '' ) {

    if ( ! $title ) { return ''; }

    $html = '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="width:100%;border-collapse:collapse;">'
      . '<tr>'
      . '<td valign="bottom" style="padding:0 0 8px 0;border-bottom:1px solid ' . esc_attr( $t['rule'] ) . ';font-family:' . esc_attr( $t['font'] ) . ';font-size:16px;line-height:20px;font-weight:700;color:' . esc_attr( $t['ink'] ) . ';">'
      . esc_html( $title ) . '</td>';

    if ( $subtitle ) {
      $html .= '<td valign="bottom" align="right" style="padding:0 0 8px 12px;border-bottom:1px solid ' . esc_attr( $t['rule'] ) . ';font-family:' . esc_attr( $t['font'] ) . ';font-size:12px;line-height:16px;color:' . esc_attr( $t['muted'] ) . ';text-align:right;">'
        . esc_html( $subtitle ) . '</td>';
    }

    $html .= '</tr></table>';

    return wcusage_email_block( $html, '4px 0 14px 0' );
  }
}

/**
 * A small sub-heading above a table, when several tables share a section.
 */
if ( ! function_exists( 'wcusage_email_subheading' ) ) {
  function wcusage_email_subheading( $text, $t ) {
    if ( ! $text ) { return ''; }
    return wcusage_email_block(
      '<div style="font-family:' . esc_attr( $t['font'] ) . ';font-size:13px;line-height:18px;font-weight:700;color:' . esc_attr( $t['body'] ) . ';">' . esc_html( $text ) . '</div>',
      '0 0 8px 0'
    );
  }
}

/**
 * A call-to-action button, built the bulletproof way so Outlook fills the whole
 * shape rather than just underlining the text.
 */
if ( ! function_exists( 'wcusage_email_button' ) ) {
  function wcusage_email_button( $text, $url, $t, $align = 'left' ) {

    if ( ! $text || ! $url ) { return ''; }

    $html = '<table role="presentation" border="0" cellpadding="0" cellspacing="0" style="border-collapse:separate;">'
      . '<tr><td bgcolor="' . esc_attr( $t['accent'] ) . '" style="padding:0;background-color:' . esc_attr( $t['accent'] ) . ';border-radius:6px;">'
      . '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener" style="display:inline-block;padding:13px 28px;font-family:' . esc_attr( $t['font'] )
      . ';font-size:14px;line-height:18px;font-weight:700;color:' . esc_attr( $t['on_accent'] ) . ';text-decoration:none;border-radius:6px;">'
      . esc_html( $text ) . '</a></td></tr></table>';

    if ( $align === 'center' ) {
      $html = '<div style="text-align:center;"><table role="presentation" border="0" cellpadding="0" cellspacing="0" align="center" style="margin:0 auto;border-collapse:separate;"><tr><td style="padding:0;">' . $html . '</td></tr></table></div>';
    }

    return wcusage_email_block( $html, '4px 0 28px 0' );
  }
}

/**
 * A soft box for milestones.
 */
if ( ! function_exists( 'wcusage_email_callout' ) ) {
  function wcusage_email_callout( $lines, $t, $title = '' ) {

    $lines = array_values( array_filter( (array) $lines ) );
    if ( empty( $lines ) ) { return ''; }

    $html = '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="width:100%;border-collapse:separate;background-color:' . esc_attr( $t['accent_soft'] ) . ';border-radius:' . esc_attr( $t['radius'] ) . ';">'
      . '<tr><td style="padding:18px 20px;font-family:' . esc_attr( $t['font'] ) . ';">';

    if ( $title ) {
      $html .= '<div style="font-size:10px;letter-spacing:0.07em;text-transform:uppercase;font-weight:700;color:' . esc_attr( $t['accent'] ) . ';padding-bottom:10px;">'
        . esc_html( $title ) . '</div>';
    }

    $count = count( $lines );
    foreach ( $lines as $index => $line ) {
      $html .= '<div style="font-size:13px;line-height:19px;color:' . esc_attr( $t['body'] ) . ';' . ( $index === $count - 1 ? '' : 'padding-bottom:6px;' ) . '">' . esc_html( $line ) . '</div>';
    }

    $html .= '</td></tr></table>';

    return wcusage_email_block( $html );
  }
}

/**
 * A labelled progress bar, for the leaderboard position.
 */
if ( ! function_exists( 'wcusage_email_progress' ) ) {
  function wcusage_email_progress( $label, $caption, $percent, $t ) {

    $percent = max( 0, min( 100, (float) $percent ) );
    $filled  = max( 2, (int) round( $percent ) );

    $html = '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="width:100%;border-collapse:separate;background-color:' . esc_attr( $t['white'] ) . ';border:1px solid ' . esc_attr( $t['rule'] ) . ';border-radius:' . esc_attr( $t['radius'] ) . ';">'
      . '<tr><td style="padding:16px 18px;font-family:' . esc_attr( $t['font'] ) . ';">'
      . '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="width:100%;border-collapse:collapse;"><tr>'
      . '<td style="padding:0;font-family:' . esc_attr( $t['font'] ) . ';font-size:13px;font-weight:700;color:' . esc_attr( $t['ink'] ) . ';">' . esc_html( $label ) . '</td>'
      . '<td align="right" style="padding:0;font-family:' . esc_attr( $t['font'] ) . ';font-size:13px;font-weight:700;color:' . esc_attr( $t['accent'] ) . ';text-align:right;">' . esc_html( number_format( $percent, 0 ) . '%' ) . '</td>'
      . '</tr></table>'
      . '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="width:100%;border-collapse:collapse;margin-top:10px;background-color:' . esc_attr( $t['rule'] ) . ';border-radius:3px;"><tr>'
      . '<td width="' . esc_attr( $filled ) . '%" height="6" bgcolor="' . esc_attr( $t['accent'] ) . '" style="width:' . esc_attr( $filled ) . '%;height:6px;padding:0;background-color:' . esc_attr( $t['accent'] ) . ';font-size:0;line-height:0;border-radius:3px;">&nbsp;</td>'
      . '<td height="6" style="height:6px;padding:0;font-size:0;line-height:0;">&nbsp;</td>'
      . '</tr></table>';

    if ( $caption ) {
      $html .= '<div style="font-size:12px;line-height:16px;color:' . esc_attr( $t['muted'] ) . ';padding-top:10px;">' . esc_html( $caption ) . '</div>';
    }

    $html .= '</td></tr></table>';

    return wcusage_email_block( $html );
  }
}

/**
 * A hairline separator.
 */
if ( ! function_exists( 'wcusage_email_divider' ) ) {
  function wcusage_email_divider( $t ) {
    return '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="width:100%;border-collapse:collapse;">'
      . '<tr><td height="1" style="height:1px;border-top:1px solid ' . esc_attr( $t['rule'] ) . ';font-size:0;line-height:0;padding:4px 0 24px 0;">&nbsp;</td></tr></table>';
  }
}

/**
 * A paragraph of site-owner supplied copy. Editor content, so it keeps its
 * markup and is filtered rather than escaped.
 */
if ( ! function_exists( 'wcusage_email_richtext' ) ) {
  function wcusage_email_richtext( $content, $t, $size = '14px' ) {
    $content = trim( (string) $content );
    // A cleared editor leaves "<p></p>" or "<p><br></p>" behind, which is not
    // empty as a string but renders as a tall blank gap.
    if ( $content === '' || trim( wp_strip_all_tags( str_replace( '&nbsp;', ' ', $content ) ) ) === '' ) { return ''; }
    return wcusage_email_block(
      '<div style="font-family:' . esc_attr( $t['font'] ) . ';font-size:' . esc_attr( $size ) . ';line-height:22px;color:' . esc_attr( $t['body'] ) . ';">'
      . wp_kses_post( wpautop( $content ) ) . '</div>',
      '0 0 20px 0'
    );
  }
}

/**
 * The small print under the report - a caption line under a section.
 */
if ( ! function_exists( 'wcusage_email_footnote' ) ) {
  function wcusage_email_footnote( $lines, $t ) {

    $lines = array_values( array_filter( (array) $lines ) );
    if ( empty( $lines ) ) { return ''; }

    $html = '<div style="font-family:' . esc_attr( $t['font'] ) . ';font-size:12px;line-height:17px;color:' . esc_attr( $t['muted'] ) . ';">'
      . implode( '<br/>', array_map( 'esc_html', $lines ) ) . '</div>';

    return wcusage_email_block( $html, '0 0 28px 0' );
  }
}
