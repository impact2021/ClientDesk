<?php
/**
 * Impact Websites Theme — index.php
 * All content is rendered by the ClientDesk plugin.
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
<?php iw_render_body(); ?>
<?php iw_render_footer(); ?>
<?php wp_footer(); ?>
</body>
</html>
