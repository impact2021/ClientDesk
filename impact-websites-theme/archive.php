<?php
/**
 * Impact Websites Theme — archive.php
 * Template for post archives, categories, and tags.
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
<main id="iw-archive">
    <header class="archive-header">
        <?php the_archive_title( '<h1 class="archive-title">', '</h1>' ); ?>
        <?php the_archive_description( '<div class="archive-description">', '</div>' ); ?>
    </header>

    <?php if ( have_posts() ) : ?>
        <ul class="post-list">
        <?php while ( have_posts() ) : the_post(); ?>
            <li class="post-list__item">
                <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
                <time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time>
                <?php the_excerpt(); ?>
            </li>
        <?php endwhile; ?>
        </ul>
        <?php the_posts_pagination(); ?>
    <?php else : ?>
        <p><?php esc_html_e( 'No posts found.', 'impact-websites' ); ?></p>
    <?php endif; ?>
</main>
<?php iw_render_footer(); ?>
<?php wp_footer(); ?>
</body>
</html>
