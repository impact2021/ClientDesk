<?php
/**
 * Impact Websites Theme — single.php
 * Template for single WordPress posts.
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
<main id="iw-post">
    <?php while ( have_posts() ) : the_post(); ?>
    <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
        <header class="entry-header">
            <?php the_title( '<h1 class="entry-title">', '</h1>' ); ?>
            <p class="entry-meta">
                <time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time>
                <?php if ( get_the_author() ) : ?>
                    &mdash; <?php the_author(); ?>
                <?php endif; ?>
            </p>
        </header>
        <div class="entry-content">
            <?php the_content(); ?>
        </div>
    </article>
    <?php endwhile; ?>
</main>
<?php iw_render_footer(); ?>
<?php wp_footer(); ?>
</body>
</html>
