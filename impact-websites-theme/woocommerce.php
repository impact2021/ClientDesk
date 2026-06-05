<?php
/**
 * Impact Websites Theme — woocommerce.php
 * Wraps all WooCommerce pages (shop, cart, checkout, single product,
 * product category) in the site's global header/footer and outputs
 * the WooCommerce content block.
 */

if ( ! defined( 'ABSPATH' ) ) exit;
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<?php iw_render_header(); ?>
<main id="iw-woocommerce">
    <?php
    if ( is_cart() || is_checkout() ) {
        while ( have_posts() ) : the_post();
            the_content();
        endwhile;
    } else {
        woocommerce_content();
    }
    ?>
</main>
<?php iw_render_footer(); ?>
<?php wp_footer(); ?>
</body>
</html>
