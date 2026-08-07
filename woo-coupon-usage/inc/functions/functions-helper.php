<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Get affiliate text with custom terminology
 *
 * @param string $default_text The default text to use if no custom terminology is set
 * @param bool $plural Whether to return plural form
 * @return string The affiliate text
 */
if ( ! function_exists( 'wcusage_get_affiliate_text' ) ) {
    function wcusage_get_affiliate_text( $default_text = 'Affiliate', $plural = false ) {

        $custom_term = wcusage_get_setting_value( 'wcusage_field_custom_affiliate_text', '' );
        $custom_term_plural = wcusage_get_setting_value( 'wcusage_field_custom_affiliates_text', '' );

        if(!$custom_term ) {
            return $default_text; // Return default if no custom term is set
        }
        
        if ( empty( $custom_term ) ) {
            $custom_term = $default_text;
        }
        
        if ( $plural ) {
            // Use custom plural term if available, otherwise use simple pluralization
            if ( !empty( $custom_term_plural ) ) {
                $custom_term = $custom_term_plural;
            } else {
                // Simple pluralization - add 's' if not already plural
                if ( substr( $custom_term, -1 ) !== 's' ) {
                    $custom_term .= 's';
                }
            }
        }

        // Keep same case as the default text
        if ( $default_text === strtoupper( $default_text ) ) {
            $custom_term = strtoupper( $custom_term );
        } elseif ( $default_text === strtolower( $default_text ) ) {
            $custom_term = strtolower( $custom_term );
        } elseif ( $default_text === ucfirst( strtolower( $default_text ) ) ) {
            $custom_term = ucfirst( strtolower( $custom_term ) );
        } elseif ( $default_text === ucwords( strtolower( $default_text ) ) ) {
            $custom_term = ucwords( strtolower( $custom_term ) );
        }

        return $custom_term;

    }
}

/**
 * Check if a role is an affiliate group.
 *
 * Affiliate groups are created as roles named "coupon_affiliate" or prefixed
 * "coupon_affiliate_", so a role matching either is one the admin deliberately
 * assigned as a group - as opposed to an incidental role like "customer".
 *
 * @param string $role
 * @return bool
 */
if ( ! function_exists( 'wcusage_is_affiliate_group_role' ) ) {
    function wcusage_is_affiliate_group_role( $role ) {
        return $role === 'coupon_affiliate' || strpos( (string) $role, 'coupon_affiliate_' ) === 0;
    }
}

/**
 * Read a commission rate that may have been stored with text or symbols in it.
 *
 * The per-role rate fields are plain text inputs, so values like "10%", "$5" or
 * "5 percent" have been saved on real sites for years. They used to be applied
 * as-is - a bare (float) cast reads "10%" as 10 - so simply refusing anything
 * non-numeric would silently drop those rates back to the global amount and
 * change what affiliates are paid. Anything that can still be read as a number
 * is therefore recovered rather than discarded.
 *
 * The decimal separator is worked out rather than assumed: whichever of "." and
 * "," appears LAST is the decimal point, and the other is a grouping mark. This
 * matters more than it looks - stripping punctuation blindly turns the European
 * "8,5" into 85, paying ten times the intended rate.
 *
 * "1,000" is genuinely ambiguous (one thousand, or one?) and is read as 1, since
 * for a commission rate under-reading is the safer way to be wrong. Anything that
 * cannot be read as a single number - no digits at all, or a leftover separator
 * that cannot be a decimal point ("5.5.5") - returns "" so the caller falls back
 * to the global rate. A range like "10-15" is read as its first number: guessing
 * wrong there is how "10-15" becomes a 1015% commission rate.
 *
 * @param mixed $value
 * @return string A numeric string, or "" if there is no single number in there.
 */
if ( ! function_exists( 'wcusage_parse_rate_value' ) ) {
    function wcusage_parse_rate_value( $value ) {

        // Already a clean number - return it untouched, so the ordinary case is
        // never reformatted by this.
        if ( is_numeric( $value ) ) {
            return (string) $value;
        }

        if ( ! is_string( $value ) ) {
            return '';
        }

        // Keep only what a number can be built from.
        $cleaned = preg_replace( '/[^0-9.,\-]/', '', $value );
        if ( $cleaned === null || $cleaned === '' ) {
            return '';
        }

        // A minus sign only counts at the front. Further along it separates two
        // numbers ("10-15"), so stop at the first one and keep the leading number.
        $negative = ( strpos( $cleaned, '-' ) === 0 );
        $cleaned = ltrim( $cleaned, '-' );
        $range_at = strpos( $cleaned, '-' );
        if ( $range_at !== false ) {
            $cleaned = substr( $cleaned, 0, $range_at );
        }

        $last_dot   = strrpos( $cleaned, '.' );
        $last_comma = strrpos( $cleaned, ',' );

        if ( $last_dot !== false && $last_comma !== false ) {
            $decimal = ( $last_comma > $last_dot ) ? ',' : '.';
        } elseif ( $last_comma !== false ) {
            $decimal = ',';
        } else {
            $decimal = '.';
        }

        // Whichever separator is not the decimal point is a grouping mark.
        $cleaned = str_replace( ( $decimal === ',' ) ? '.' : ',', '', $cleaned );
        $cleaned = str_replace( $decimal, '.', $cleaned );

        // A number has at most one decimal point. More than one left at this stage
        // means the value was never a single number, so don't invent one from it.
        if ( substr_count( $cleaned, '.' ) > 1 || ! is_numeric( $cleaned ) ) {
            return '';
        }

        // Never silently multiply what a site has been paying. Before this parser
        // existed these values were used as they were, i.e. read by a plain float
        // cast that stops at the first character it cannot use - "8,5" was 8 and
        // "1.234,56" was 1.234. Reading the decimal comma in "8,5" as 8.5 moves the
        // rate by a fraction and is plainly what was meant; reading "1.234,56" as
        // 1234.56 would multiply an affiliate's commission by a thousand the moment
        // the site updated.
        //
        // Recovering a decimal separator can never even double the old reading (the
        // recovered part is always less than 1, so the worst case is "1,99" -> 1.99),
        // which makes 2x a boundary that accepts every genuine decimal comma and
        // rejects every grouping mark read as one. Past it the value is too ambiguous
        // to act on, so it is left to fall back to the global rate.
        $previous = (float) $value;
        if ( $previous > 0 && (float) $cleaned > ( $previous * 2 ) ) {
            return '';
        }

        return $negative ? '-' . $cleaned : $cleaned;

    }
}

/**
 * Resolve a per-role commission rate for a user.
 *
 * Users commonly hold more than one role (an affiliate group plus "customer",
 * or several affiliate groups after a migration), so picking the rate needs a
 * defined order rather than whichever role happens to come first in
 * $user->roles - that array is unordered, which previously made the resolved
 * rate differ between the checkout message, the affiliate dashboard and the
 * recorded commission for the very same user.
 *
 * Resolution order:
 *  1. Roles that are affiliate groups win over any other role, since an
 *     assigned group is an explicit choice and "customer" is not.
 *  2. Within that set, the highest rate wins (the long-standing tie-break).
 *
 * Values are read through wcusage_parse_rate_value(), so a rate stored as "13%"
 * still counts, but is compared as the number 13 rather than as a string.
 *
 * @param WP_User|null $user
 * @param string $option_prefix Setting key prefix, e.g. "wcusage_field_affiliate_percent_role_"
 * @return string The resolved rate, or "" when no role sets one.
 */
if ( ! function_exists( 'wcusage_get_role_rate' ) ) {
    function wcusage_get_role_rate( $user, $option_prefix ) {

        if ( ! $user || ! isset( $user->roles ) ) {
            return '';
        }

        $user_roles = $user->roles;
        if ( ! is_array( $user_roles ) && ! is_object( $user_roles ) ) {
            return '';
        }

        $group_rates = array();
        $other_rates = array();

        foreach ( $user_roles as $role ) {

            $rate = wcusage_parse_rate_value( wcusage_get_setting_value( $option_prefix . $role, '' ) );
            if ( $rate === '' ) {
                continue;
            }

            if ( wcusage_is_affiliate_group_role( $role ) ) {
                $group_rates[] = $rate;
            } else {
                $other_rates[] = $rate;
            }

        }

        $rates = ! empty( $group_rates ) ? $group_rates : $other_rates;
        if ( empty( $rates ) ) {
            return '';
        }

        $highest = $rates[0];
        foreach ( $rates as $rate ) {
            if ( (float) $rate > (float) $highest ) {
                $highest = $rate;
            }
        }

        return $highest;

    }
}

/**
 * Collapse the whitespace in rendered dashboard output.
 *
 * WordPress core runs wpautop before shortcodes are expanded, but many themes and
 * page builders run it afterwards, which turns the newlines in the dashboard markup
 * into stray <p> and <br/> tags. Removing them here avoids that.
 *
 * Newlines are left alone inside <script>, <pre> and <textarea>, where they carry
 * meaning: a // comment in inline JS runs to the end of its line, so collapsing the
 * newline comments out the rest of the script and the browser stops with
 * "Unexpected end of input". These blocks are passed through untouched, since the
 * few bytes saved inside them are not worth mangling template literals or the
 * whitespace <pre> and <textarea> are there to preserve.
 *
 * @param string $content The buffered output to minify.
 * @return string
 */
if ( ! function_exists( 'wcusage_minify_output' ) ) {
    function wcusage_minify_output( $content ) {

        if ( ! is_string( $content ) || $content === '' ) {
            return $content;
        }

        // Each alternative pairs its own opening and closing tag, so a <script>
        // is never closed by a </pre>.
        $parts = preg_split(
            '#(<script\b[^>]*>.*?</script>|<pre\b[^>]*>.*?</pre>|<textarea\b[^>]*>.*?</textarea>)#is',
            $content,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );

        // Splitting can fail on very large output (PCRE backtracking limits). Fall
        // back to collapsing horizontal whitespace only: the output stays larger
        // than intended, but no inline script is ever broken.
        if ( $parts === false ) {
            $fallback = preg_replace( '/[ \t]+/', ' ', $content );
            return ( $fallback === null ) ? $content : trim( $fallback );
        }

        $output = '';
        foreach ( $parts as $index => $part ) {
            // Odd indexes are the captured script/pre/textarea blocks.
            if ( $index % 2 ) {
                $output .= $part;
                continue;
            }
            // Never drop a chunk if the replace fails: leaving the spacing in is
            // only untidy, whereas losing it would take part of the page with it.
            $collapsed = preg_replace( '/\s+/', ' ', $part );
            $output .= ( $collapsed === null ) ? $part : $collapsed;
        }

        return trim( $output );

    }
}